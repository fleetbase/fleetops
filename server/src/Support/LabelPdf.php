<?php

namespace Fleetbase\FleetOps\Support;

use Barryvdh\DomPDF\Facade\Pdf;

class LabelPdf
{
    /**
     * Create a PDF instance for FleetOps labels with UTF-8 and embedded-font
     * options applied consistently across order, waypoint, and entity labels.
     */
    public static function fromHtml(string $html)
    {
        $pdf = Pdf::loadHTML($html, 'UTF-8');

        if (method_exists($pdf, 'setOptions')) {
            $pdf->setOptions(static::options());
        }

        if (method_exists($pdf, 'getDomPDF')) {
            $dompdf = $pdf->getDomPDF();
            foreach (static::dompdfOptions() as $key => $value) {
                if (method_exists($dompdf, 'set_option')) {
                    $dompdf->set_option($key, $value);
                }
            }
        }

        return $pdf;
    }

    public static function options(): array
    {
        return [
            'defaultFont'             => 'DejaVu Sans',
            'isHtml5ParserEnabled'    => true,
            'isFontSubsettingEnabled' => true,
            'isRemoteEnabled'         => false,
        ];
    }

    public static function dompdfOptions(): array
    {
        return [
            'defaultFont'             => 'DejaVu Sans',
            'isHtml5ParserEnabled'    => true,
            'isFontSubsettingEnabled' => true,
            'isRemoteEnabled'         => false,
        ];
    }
}
