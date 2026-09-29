<?php

namespace Modules\Reports\Tests\Feature\Public;

use Illuminate\Support\Facades\URL;
use Modules\Reports\Models\Report;
use Tests\TestCase;

class SignedDownloadTest extends TestCase
{
    public function test_pub_07_a_valid_signature_downloads_the_report(): void
    {
        $report = Report::factory()->create();
        $report->addMediaFromString('%PDF-1.4 fake pdf content')->preservingOriginal()->usingFileName('report.pdf')->toMediaCollection('report_file');

        $url = URL::temporarySignedRoute('public.reports.signed-download', now()->addDays(7), ['report' => $report->id]);

        $response = $this->get($url);

        $response->assertOk();
        $response->assertHeader('content-disposition', 'attachment; filename=report.pdf');
        $this->assertSame('%PDF-1.4 fake pdf content', $response->streamedContent());

        // A signed download counts as a client download (§10.7 PUB-07).
        $this->assertNotNull($report->refresh()->first_downloaded_at);
    }

    public function test_pub_07_an_invalid_signature_returns_403(): void
    {
        $report = Report::factory()->create();
        $report->addMediaFromString('%PDF-1.4 fake pdf content')->preservingOriginal()->toMediaCollection('report_file');

        $url = URL::temporarySignedRoute('public.reports.signed-download', now()->addDays(7), ['report' => $report->id]);

        // Tamper with the URL.
        $this->get($url.'tampered')->assertForbidden();
        $this->get('/api/v1/public/reports/'.$report->id.'/signed-download')->assertForbidden();
    }
}
