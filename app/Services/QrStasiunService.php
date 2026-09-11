<?php

namespace App\Services;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * QR dirender SVG LOKAL, pola sama persis dengan TwoFactorAuthService::qrCodeSvg() —
 * satu definisi cara render QR di seluruh aplikasi, cuma beda ukuran & isi konten.
 * Ukuran 240px (lebih besar dari QR 2FA yang 200px) karena QR stasiun dicetak dan
 * discan dari jarak lebih jauh, bukan dilihat dari layar dekat.
 */
class QrStasiunService
{
    public function svgFor(string $url): string
    {
        $renderer = new ImageRenderer(new RendererStyle(240), new SvgImageBackEnd);

        return (new Writer($renderer))->writeString($url);
    }
}
