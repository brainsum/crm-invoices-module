<?php

namespace Crm\InvoicesModule;

use Crm\ApplicationModule\Config\ApplicationConfig;
use Crm\ApplicationModule\Helpers\PriceHelper;
use Crm\ApplicationModule\RedisClientFactory;
use Crm\ApplicationModule\RedisClientTrait;
use Crm\InvoicesModule\Model\InvoiceNumberInterface;
use Crm\InvoicesModule\Repository\InvoicesRepository;
use Crm\PaymentsModule\Repository\PaymentsRepository;
use Kdyby\Translation\Translator;
use Latte\Engine;
use malkusch\lock\mutex\PredisMutex;
use Mpdf\Mpdf;
use Nette\Database\Table\ActiveRow;
use Nette\Http\Request;
use Nette\Http\Response;
use Nette\Http\UrlScript;
use PdfResponse\PdfResponse;
use Tracy\Debugger;

class InvoiceGenerator
{
    use RedisClientTrait;

    const CAN_GENERATE_DAYS_LIMIT = 15;

    /** @var string */
    private $templateFile;

    /** @var string */
    private $tempDir;

    private $invoicesRepository;

    private $paymentsRepository;

    private $applicationConfig;

    private $priceHelper;

    private $translator;

    private $invoiceNumber;

    public function __construct(
        InvoicesRepository $invoicesRepository,
        PaymentsRepository $paymentsRepository,
        ApplicationConfig $applicationConfig,
        PriceHelper $priceHelper,
        Translator $translator,
        InvoiceNumberInterface $invoiceNumber,
        RedisClientFactory $redisClientFactory
    ) {
        $this->invoicesRepository = $invoicesRepository;
        $this->paymentsRepository = $paymentsRepository;
        $this->applicationConfig = $applicationConfig;
        $this->priceHelper = $priceHelper;
        $this->translator = $translator;
        $this->invoiceNumber = $invoiceNumber;
        $this->redisClientFactory = $redisClientFactory;
    }

    public function setTempDir(string $tempDir)
    {
        if (!is_dir($tempDir)) {
            Debugger::log("Providid temp dir {$tempDir} is not directory. System temp directory will be used.", Debugger::ERROR);
            return;
        }
        $this->tempDir = $tempDir;
    }

    public function getTempDir(): string
    {
        // if no temp dir was provided, use system temp dir
        if ($this->tempDir === null) {
            $this->tempDir = sys_get_temp_dir();
        }
        return $this->tempDir;
    }

    public function setTemplateFile(string $templateFile)
    {
        if (!file_exists($templateFile)) {
            Debugger::log("Unable to find provided invoice template file {$templateFile}. Default template will be used.", Debugger::ERROR);
            return;
        }
        $this->templateFile = $templateFile;
    }

    public function getTemplateFile(): string
    {
        // if no invoice file was provided, load default
        if ($this->templateFile === null) {
            $this->templateFile = __DIR__ . "/templates/invoice/default.latte";
        }
        return $this->templateFile;
    }

    public function generate($user, $payment)
    {
        if (!$this->invoicesRepository->isPaymentInvoiceable($payment)) {
            throw new InvoiceGenerationException("Trying to generate invoice for payment [{$payment->id}] which is not invoiceable.");
        }

        $mutex = new PredisMutex([$this->redis()], 'invoice_generator_' . $payment->id);

        $mutex->synchronized(function () use ($user, $payment) {
            $payment = $this->paymentsRepository->find($payment->id);

            if ($payment->invoice_id === null) {
                $invoiceNumber = $this->invoiceNumber->getNextInvoiceNumber($payment);
                $invoice = $this->invoicesRepository->add($user, $payment, $invoiceNumber);

                $this->paymentsRepository->update($payment, ['invoice_id' => $invoice->id]);
		return true;
            }
        });

        //$payment = $this->paymentsRepository->find($payment->id);

        return null;
    }

    public function renderInvoicePDF($user, $payment)
    {
        if ($payment->user->id == $user->id) {
            return $this->renderInvoice($payment);
        }
        return null;
    }

    public function renderInvoicePDFToFile($filePath, $user, $payment)
    {
        if ($payment->user->id == $user->id) {
            $pdf = $this->renderInvoice($payment);
            $pdf->outputDestination = PdfResponse::OUTPUT_FILE;
            $pdf->outputName = $filePath;
            $pdf->send(new Request(new UrlScript()), new Response());
            return $pdf;
        }
        return null;
    }


    /**
     * @param ActiveRow $payment
     *
     * @return PdfResponse
     * @throws InvoiceGenerationException
     */
    private function renderInvoice(ActiveRow $payment)
    {
        $invoice = $this->invoicesRepository->find($payment->invoice_id);
        $engine = new Engine();
        $engine->addFilter('price', [$this->priceHelper, 'process']);
        $engine->addFilter('translate', [$this->translator, 'translate']);

        $template = $engine->renderToString(
            $this->getTemplateFile(),
            [
                'invoice' => $invoice,
                'config' => $this->applicationConfig,
            ]
        );

        if (!$template) {
            throw new InvoiceGenerationException("Error in rendering invoice template for payment #{$payment->id}", 100);
        }

        $pdf = new PdfResponse($template);
        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'default_font_size' => '',
            'default_font' => '',
            'margin_left' => 10,
            'margin_right' => 10,
            'margin_top' => 10,
            'margin_bottom' => 10,
            'margin_header' => 2,
            'margin_footer' => 6,
            'orientation' => PdfResponse::ORIENTATION_PORTRAIT,
            'tempDir' => $this->getTempDir(),
        ]);
        $pdf->createMPDF = function () use ($mpdf) {
            return $mpdf;
        };
        $pdf->documentTitle = 'Invoice';
        $pdf->documentAuthor = $this->applicationConfig->get('supplier_name');
        $pdf->tempDir = $this->getTempDir();
        return $pdf;
    }


    /**
     * Generates invoice PDF file as attachment.
     *
     * If invoice isn't generated for payment and user allowed invoicing, invoice will be generated and linked to payment.
     *
     * @param ActiveRow $payment
     *
     * @return array|bool Returns false if user disabled invoicing.
     * @throws InvoiceGenerationException
     */
    public function renderInvoiceMailAttachment(ActiveRow $payment)
    {
        if (!$payment->user->invoice || $payment->user->disable_auto_invoice) {
            // user (or admin) disabled invoicing for this account; nothing to generate
            return false;
        }

        if (!$payment->invoice_id) {
            $this->generate($payment->user, $payment);
            $payment = $this->paymentsRepository->find($payment->id); // refresh the instance to get invoice ID
        }

        $attachment = [
            'file' => $payment->variable_symbol . '.pdf',
            'content' => $this->generateInvoiceAsString($payment),
            'mime_type' => 'application/pdf',
        ];

        return $attachment;
    }

    /**
     * Generates invoice PDF file and returns contents as string. Invoice must be already generated and linked to payment.
     */
    public function generateInvoiceAsString(ActiveRow $payment): string
    {
        if ($payment->invoice_id === null) {
            throw new InvoiceGenerationException("No linked invoice for payment VS {$payment->variable_symbol}. Cannot generate PDF attachment.");
        }

        $invoicePdfFile = tmpfile();
        $invoicePdfFilePath = stream_get_meta_data($invoicePdfFile)['uri'];

        $this->renderInvoicePDFToFile(
            $invoicePdfFilePath,
            $payment->user,
            $payment
        );

        if (!file_exists($invoicePdfFilePath)) {
            throw new InvoiceGenerationException("Cannot generate invoice PDF for payment VS {$payment->variable_symbol}.");
        }

        $invoicePdfAsString = file_get_contents($invoicePdfFilePath);
        fclose($invoicePdfFile);

        return $invoicePdfAsString;
    }
}
