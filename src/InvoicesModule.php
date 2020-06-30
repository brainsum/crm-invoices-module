<?php

namespace Crm\InvoicesModule;

use Crm\ApplicationModule\Commands\CommandsContainerInterface;
use Crm\ApplicationModule\CrmModule;
use Crm\ApplicationModule\DataProvider\DataProviderManager;
use Crm\ApplicationModule\Menu\MenuContainerInterface;
use Crm\ApplicationModule\Menu\MenuItem;
use Crm\ApplicationModule\SeederManager;
use Crm\ApplicationModule\User\UserDataRegistrator;
use Crm\ApplicationModule\Widget\WidgetManagerInterface;
use Crm\InvoicesModule\Seeders\AddressTypesSeeder;
use Crm\InvoicesModule\Seeders\ConfigsSeeder;
use Crm\InvoicesModule\Seeders\PaymentGatewaysSeeder;
use League\Event\Emitter;
use Tomaj\Hermes\Dispatcher;

class InvoicesModule extends CrmModule
{
    public function registerAdminMenuItems(MenuContainerInterface $menuContainer)
    {
        $mainMenu = new MenuItem($this->translator->translate('payments.menu.admin_payments'), '#payments', 'fa fa-file=invoice', 240);

        $menuItem = new MenuItem(
            $this->translator->translate('invoices.admin.menu.export'),
            ':Invoices:InvoicesAdmin:default',
            'fa fa-file-invoice',
            1200
        );
        $menuContainer->attachMenuItemToForeignModule('#payments', $mainMenu, $menuItem);
    }

    public function registerFrontendMenuItems(MenuContainerInterface $menuContainer)
    {
        $menuItem = new MenuItem($this->translator->translate('invoices.menu.invoice_details'), ':Invoices:Invoices:invoiceDetails', '', 550, true);
        $menuContainer->attachMenuItem($menuItem);
    }

    public function registerWidgets(WidgetManagerInterface $widgetManager)
    {
        $widgetManager->registerWidgetFactory(
            'admin.payments.listing.action',
            $this->getInstance(\Crm\InvoicesModule\Components\InvoiceAdminButtonFactory::class),
            400
        );
        $widgetManager->registerWidgetFactory(
            'frontend.payments.listing.receipts',
            $this->getInstance(\Crm\InvoicesModule\Components\InvoiceFrontendButtonFactory::class),
            400
        );
        $widgetManager->registerWidgetFactory(
            'frontend.payments.refund.receipts',
            $this->getInstance(\Crm\InvoicesModule\Components\InvoiceFrontendButtonFactory::class),
            400
        );
        $widgetManager->registerWidget(
            'frontend.payments.listing.receipts',
            $this->getInstance(\Crm\InvoicesModule\Components\DownloadReceiptButton::class),
            500
        );

        $widgetManager->registerWidget(
            'admin.payments.listing.action',
            $this->getInstance(\Crm\InvoicesModule\Components\DownloadReceiptButton::class),
            500
        );

        $widgetManager->registerWidget(
            'frontend.payment.success.forms',
            $this->getInstance(\Crm\InvoicesModule\Components\PaymentSuccessInvoiceWidget::class)
        );
        $widgetManager->registerWidget(
            'admin.user.list.emailcolumn',
            $this->getInstance(\Crm\InvoicesModule\Components\InvoiceLabel::class)
        );
    }

    public function registerHermesHandlers(Dispatcher $dispatcher)
    {
        $dispatcher->registerHandler(
            'invoice_zip',
            $this->getInstance(\Crm\InvoicesModule\Hermes\ZipInvoicesHandler::class)
        );
        $dispatcher->registerHandler(
            'generate_invoice',
            $this->getInstance(\Crm\InvoicesModule\Hermes\GenerateInvoiceHandler::class)
        );
    }

    public function registerSeeders(SeederManager $seederManager)
    {
        $seederManager->addSeeder($this->getInstance(ConfigsSeeder::class));
        $seederManager->addSeeder($this->getInstance(AddressTypesSeeder::class));
        $seederManager->addSeeder($this->getInstance(PaymentGatewaysSeeder::class));
    }

    public function registerEventHandlers(Emitter $emitter)
    {
        $emitter->addListener(
            \Crm\UsersModule\Events\AddressChangedEvent::class,
            $this->getInstance(\Crm\InvoicesModule\Events\AddressChangedHandler::class)
        );
        $emitter->addListener(
            \Crm\UsersModule\Events\NewAddressEvent::class,
            $this->getInstance(\Crm\InvoicesModule\Events\AddressChangedHandler::class)
        );
        $emitter->addListener(
            \Crm\UsersModule\Events\AddressRemovedEvent::class,
            $this->getInstance(\Crm\InvoicesModule\Events\AddressRemovedHandler::class)
        );
        $emitter->addListener(
            \Crm\UsersModule\Events\PreNotificationEvent::class,
            $this->getInstance(\Crm\InvoicesModule\Events\PreNotificationEventHandler::class)
        );
        $emitter->addListener(
            \Crm\UsersModule\Events\NewAddressEvent::class,
            $this->getInstance(\Crm\InvoicesModule\Events\NewAddressHandler::class)
        );
        $emitter->addListener(
            \Crm\PaymentsModule\Events\PaymentChangeStatusEvent::class,
            $this->getInstance(\Crm\InvoicesModule\Events\PaymentStatusChangeHandler::class)
        );
    }

    public function registerCommands(CommandsContainerInterface $commandsContainer)
    {
        $commandsContainer->registerCommand($this->getInstance(\Crm\InvoicesModule\Commands\SendInvoiceCommand::class));
    }

    public function registerDataProviders(DataProviderManager $dataProviderManager)
    {
        $dataProviderManager->registerDataProvider(
            'users.dataprovider.user_form',
            $this->getInstance(\Crm\InvoicesModule\DataProvider\UserFormDataProvider::class)
        );
    }

    public function registerUserData(UserDataRegistrator $dataRegistrator)
    {
        // We don't generate pdf
        // $dataRegistrator->addUserDataProvider($this->getInstance(\Crm\InvoicesModule\User\InvoicesUserDataProvider::class));
    }
}
