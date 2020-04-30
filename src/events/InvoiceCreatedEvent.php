<?php

namespace Crm\InvoicesModule\Events;

use League\Event\AbstractEvent;

class InvoiceCreatedEvent extends AbstractEvent
{
    private $payment;

    private $pdf;

    public function __construct($payment, $pdf)
    {
        $this->payment = $payment;
        $this->pdf = $pdf;
    }

    public function getPayment()
    {
        return $this->payment;
    }

    public function getPDF()
    {
        return $this->pdf;
    }
}
