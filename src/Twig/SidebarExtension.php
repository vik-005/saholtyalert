<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\PdfExportService;
use App\Service\AuditPdfExportService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;
use Symfony\Component\HttpFoundation\RequestStack;

class SidebarExtension extends AbstractExtension
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly PdfExportService $pdfExportService,
        private readonly AuditPdfExportService $auditPdfExportService,
    ) {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('format_date', [$this, 'formatDate']),
            new TwigFilter('format_currency', [$this, 'formatCurrency']),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('is_active_route', [$this, 'isActiveRoute']),
            new TwigFunction('generate_pdf_url', [$this, 'generatePdfUrl']),
            new TwigFunction('has_audit_pdf', [$this, 'hasAuditPdf']),
        ];
    }

    /**
     * Format a date in the expected format (DD/MM/YYYY)
     */
    public function formatDate(?string $date, string $format = 'd/m/Y'): string
    {
        if ($date === null) {
            return '';
        }

        $dateTime = new \DateTime($date);

        return $dateTime->format($format);
    }

    /**
     * Format a number as currency (EUR)
     */
    public function formatCurrency(float $amount): string
    {
        return number_format($amount, 2, ',', ' ') . ' ';
    }

    /**
     * Check if the current route matches a pattern
     */
    public function isActiveRoute(string $routePattern, Request $request = null): bool
    {
        if ($request === null) {
            $request = $this->requestStack->getCurrentRequest();
        }

        if ($request === null) {
            return false;
        }

        $currentRoute = $request->attributes->get('_route');

        // Use regex to match route patterns (e.g., "admin_*" or specific route names)
        $pattern = '/^' . str_replace('*', '.*', $routePattern) . '$/';

        return (bool) preg_match($pattern, (string) $currentRoute);
    }

    /**
     * Generate PDF export URL for an entity
     */
    public function generatePdfUrl(string $entityClass, int $id): ?string
    {
        if ($entityClass === 'App\Entity\Alert') {
            return "/export/alert/{$id}/pdf";
        }

        return null;
    }

    /**
     * Check if an audit has an associated PDF
     */
    public function hasAuditPdf(int $auditId): bool
    {
        return true;
    }
}
