<?php

namespace Crm\InvoicesModule\Presenters;

use Crm\ApplicationModule\Presenters\FrontendPresenter;
use Crm\InvoicesModule\Forms\ChangeInvoiceDetailsFormFactory;
use Crm\InvoicesModule\Events\InvoiceCreatedEvent;
use Crm\InvoicesModule\InvoiceGenerator;
use Crm\PaymentsModule\Repository\PaymentsRepository;
use DateTime;
use Nette\Application\BadRequestException;

class InvoicesPresenter extends FrontendPresenter
{
    /** @var InvoiceGenerator @inject */
    public $invoiceGenerator;

    /** @var PaymentsRepository @inject */
    public $paymentsRepository;

    /** @var ChangeInvoiceDetailsFormFactory @inject */
    public $changeInvoiceDetailsFormFactory;

    public function actionDownloadInvoice($id)
    {
        list($user, $payment) = $this->getUserPayment($id);

        $pdf = null;
        if ($payment->invoice) {
            $pdf = $this->invoiceGenerator->renderInvoicePDF($user, $payment);
        } else {
            if ($payment->user->invoice == true && !$payment->user->disable_auto_invoice) {
                if ($payment->paid_at->diff(new DateTime('now'))->days <= InvoiceGenerator::CAN_GENERATE_DAYS_LIMIT) {
                    $pdf = $this->invoiceGenerator->generate($user, $payment);
                    $this->emitter->emit(new InvoiceCreatedEvent($payment, $pdf));
                }
            }
        }

        if (!$pdf) {
            throw new BadRequestException();
        }

        $this->sendResponse($pdf);
        $this->terminate();
    }

    private function getUserPayment($id)
    {
        $this->onlyLoggedIn();

        $user = $this->getUser();
        $payment = $this->paymentsRepository->find($id);

        if (!$payment) {
            throw new BadRequestException();
        }

        if ($user->id != $payment->user->id) {
            throw new BadRequestException();
        }

        $user = $this->usersRepository->find($user->id);

        if (!$user) {
            throw new BadRequestException();
        }

        return [$user, $payment];
    }

    public function renderInvoiceDetails()
    {
        $this->onlyLoggedIn();
        $userRow = $this->usersRepository->find($this->getUser()->id);
        $this->template->userRow = $userRow;
    }

    public function createComponentChangeInvoiceDetailsForm()
    {
        $form = $this->changeInvoiceDetailsFormFactory->create($this->getUser());

        $this->changeInvoiceDetailsFormFactory->onSuccess = function ($form, $user) {
            $message = $this->translator->translate(
                'invoices.frontend.change_invoice_details.warning',
                null,
                [
                    'link' => $this->link(':Payments:Payments:my')
                ]
            );

            $this->flashMessage($this->translator->translate('invoices.frontend.change_invoice_details.success'));
            $this->flashMessage($message, 'warning');
            $this->redirect('invoiceDetails');
        };
        return $form;
    }
}
