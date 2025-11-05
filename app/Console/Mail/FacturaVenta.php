<?php

namespace App\Mail;

use App\Models\Venta;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Attachment;
use Barryvdh\DomPDF\Facade\Pdf;

class FacturaVenta extends Mailable
{
    public $venta;

    public function __construct(Venta $venta)
    {
        $this->venta = $venta;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            "Factura #{$this->venta->numero_factura} - Comercial Palermo"
        );
    }

    public function content(): Content
    {
        return new Content(
            'emails.factura',
            [
                'venta' => $this->venta,
                'cliente' => $this->venta->nombre_cliente,
                'numeroFactura' => $this->venta->numero_factura,
                'total' => $this->venta->total
            ]
        );
    }

    public function attachments(): array
    {
        $pdf = PDF::loadView('facturas.venta', ['venta' => $this->venta])
                 ->setPaper('a4', 'portrait');

        return [
            Attachment::fromData(
                fn () => $pdf->output(),
                "factura-{$this->venta->numero_factura}.pdf"
            )->withMime('application/pdf'),
        ];
    }
}