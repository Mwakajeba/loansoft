<?php

namespace App\Support\Pdf;

class PdfLogo
{
    /**
     * DomPDF requires the GD extension to embed raster images (PNG/JPG).
     */
    public static function canEmbedImages(): bool
    {
        return extension_loaded('gd');
    }

    /**
     * @return string|null data URI for PDF img src, or null when GD is unavailable / no logo file
     */
    public static function dataUri(?object $company = null): ?string
    {
        if (!self::canEmbedImages()) {
            return null;
        }

        $logoPath = null;

        if ($company && !empty($company->logo)) {
            $storagePath = public_path('storage/' . $company->logo);
            if (is_file($storagePath)) {
                $logoPath = $storagePath;
            }
        }

        if (!$logoPath && is_file(public_path('assets/images/logo-img.png'))) {
            $logoPath = public_path('assets/images/logo-img.png');
        }

        if (!$logoPath) {
            return null;
        }

        $logoType = pathinfo($logoPath, PATHINFO_EXTENSION) ?: 'png';
        $logoData = file_get_contents($logoPath);

        if ($logoData === false) {
            return null;
        }

        return 'data:image/' . $logoType . ';base64,' . base64_encode($logoData);
    }
}
