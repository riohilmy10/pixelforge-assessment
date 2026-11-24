<?php

namespace App\Http\Controllers;

use App\Http\Requests\AdminReportRequest;
use App\Services\AdminReportService;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Operator-only usage report. Access is enforced by the `admin` route middleware and
 * again by the form request, so neither layer alone is responsible for the guard.
 */
class AdminReportController extends Controller
{
    public function __construct(private readonly AdminReportService $report) {}

    public function index(AdminReportRequest $request): Response
    {
        return Inertia::render('Admin/Report', [
            'report' => $this->report->report(
                $request->startDate(),
                $request->endDate(),
                $request->boolean('refresh'),
            ),
        ]);
    }
}
