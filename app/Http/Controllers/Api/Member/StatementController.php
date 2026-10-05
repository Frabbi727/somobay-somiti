<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Member;

use App\Http\Api\ApiResponse;
use App\Http\Api\ApiValue;
use App\Http\Controllers\Api\Member\Concerns\ResolvesMember;
use App\Reports\Definitions\MemberStatementReport;
use App\Reports\ReportExporter;
use App\Support\Money\Money;
use DateTimeInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * The portal's statement (Filament/Member/Pages/Statement) with the member fixed to the token's,
 * whatever the request says.
 */
final class StatementController
{
    use ResolvesMember;

    public function __construct(private readonly MemberStatementReport $report) {}

    public function show(Request $request): JsonResponse
    {
        $filters = $this->filters($request);
        $data = $this->report->data($filters);
        abort_if($data === null, 404);

        return ApiResponse::ok([
            'from' => $filters['from'],
            'until' => $filters['until'],
            'opening' => ApiValue::money($data['opening']),
            'rows' => array_map(fn (array $row): array => [
                'date' => $row['date'] instanceof DateTimeInterface ? ApiValue::date($row['date']) : null,
                'description' => (string) $row['description'],
                'charge' => $row['charge'] instanceof Money ? ApiValue::money($row['charge']) : null,
                'paid' => $row['paid'] instanceof Money ? ApiValue::money($row['paid']) : null,
                'balance' => $row['balance'] instanceof Money ? ApiValue::money($row['balance']) : null,
            ], $data['rows']),
            'total_charges' => ApiValue::money($data['total_charges']),
            'total_paid' => ApiValue::money($data['total_paid']),
            'closing' => ApiValue::money($data['closing']),
        ]);
    }

    public function pdf(Request $request, ReportExporter $exporter): Response
    {
        $filters = $this->filters($request);
        $content = $exporter->pdf($this->report, $filters);
        abort_if($content === null, 404);

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$this->report->filename($filters).'.pdf"',
        ]);
    }

    /**
     * A 15-minute signed link to the PDF, for opening in the phone's browser (which cannot send
     * the access token). The member id is part of the signature, so it cannot be changed.
     */
    public function pdfLink(Request $request): JsonResponse
    {
        return ApiResponse::ok(['url' => URL::temporarySignedRoute('api.statement.signed', now()->addMinutes(15), $this->filters($request))]);
    }

    public function pdfSigned(Request $request, ReportExporter $exporter): Response
    {
        $filters = ['member' => $request->integer('member'), 'from' => (string) $request->string('from'), 'until' => (string) $request->string('until')];
        $content = $exporter->pdf($this->report, $filters);
        abort_if($content === null, 404);

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$this->report->filename($filters).'.pdf"',
        ]);
    }

    /**
     * @return array{member: int, from: string, until: string}
     */
    private function filters(Request $request): array
    {
        $valid = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $defaults = $this->report->defaults();

        return [
            'member' => self::member($request)->id,
            'from' => (string) ($valid['from'] ?? $defaults['from']),
            'until' => (string) ($valid['until'] ?? $defaults['until']),
        ];
    }
}
