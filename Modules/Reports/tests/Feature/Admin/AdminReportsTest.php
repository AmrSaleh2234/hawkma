<?php

namespace Modules\Reports\Tests\Feature\Admin;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Modules\Bookings\Models\Booking;
use Modules\Reports\Models\Report;
use Modules\Reports\Notifications\ReportReadyNotification;
use Tests\TestCase;

class AdminReportsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    /*
    |----------------------------------------------------------------------
    | RPT-03 POST /api/v1/admin/bookings/{booking}/report
    |----------------------------------------------------------------------
    */

    public function test_rpt_03_uploads_a_report_for_a_completed_booking(): void
    {
        $this->actingAsAdmin();
        $booking = Booking::factory()->withReportPending()->create();

        $response = $this->postJson("/api/v1/admin/bookings/{$booking->id}/report", [
            'title' => 'Governance assessment report',
            'summary' => 'A summary',
            'file' => UploadedFile::fake()->createWithContent('report.pdf', '%PDF-1.4 fake pdf content'),
        ]);

        $this->assertApiSuccess($response, 201)
            ->assertJsonPath('data.title', 'Governance assessment report')
            ->assertJsonPath('data.file.name', 'report.pdf')
            ->assertJsonPath('data.file.mime_type', 'application/pdf');

        $report = Report::sole();
        $this->assertSame($booking->id, $report->booking_id);
        $this->assertSame($booking->consultant_id, $report->consultant_id);
        $this->assertSame($booking->client_id, $report->client_id);

        // The file is stored on the private disk.
        $media = $report->getFirstMedia('report_file');
        $this->assertNotNull($media);
        $this->assertTrue(Storage::disk('local')->exists($media->getPathRelativeToRoot()));

        // The booking flips to uploaded and the client is notified.
        $this->assertSame('uploaded', $booking->refresh()->report_status->value);
        $this->assertNotNull($report->client_notified_at);

        Notification::assertSentTo(
            $report->client,
            ReportReadyNotification::class,
            function ($notification) use ($report) {
                $mail = $notification->toMail($report->client);
                // Lines added after the action land in outroLines.
                $body = implode(' ', array_merge($mail->introLines, $mail->outroLines));

                return str_contains($mail->actionUrl ?? '', '/reports/'.$report->id)
                    && str_contains($body, 'signed-download');
            },
        );
    }

    public function test_rpt_03_a_not_completed_booking_returns_422(): void
    {
        $this->actingAsAdmin();
        $booking = Booking::factory()->pending()->future()->create();

        $this->assertApiError(
            $this->postJson("/api/v1/admin/bookings/{$booking->id}/report", [
                'title' => 'x',
                'file' => UploadedFile::fake()->createWithContent('report.pdf', '%PDF-1.4 fake pdf content'),
            ]),
            422,
            'REPORT_NOT_ALLOWED',
        );
    }

    public function test_rpt_03_an_exe_file_is_rejected(): void
    {
        $this->actingAsAdmin();
        $booking = Booking::factory()->withReportPending()->create();

        $this->postJson("/api/v1/admin/bookings/{$booking->id}/report", [
            'title' => 'x',
            'file' => UploadedFile::fake()->create('virus.exe', 100, 'application/x-msdownload'),
        ])->assertUnprocessable();
    }

    public function test_rpt_03_another_consultant_gets_403(): void
    {
        $this->actingAsConsultant(); // not the booking's consultant
        $booking = Booking::factory()->withReportPending()->create();

        $this->postJson("/api/v1/admin/bookings/{$booking->id}/report", [
            'title' => 'x',
            'file' => UploadedFile::fake()->createWithContent('report.pdf', '%PDF-1.4 fake pdf content'),
        ])->assertForbidden();
    }

    public function test_rpt_03_replacing_keeps_one_report_row_and_one_file(): void
    {
        $this->actingAsAdmin();
        $booking = Booking::factory()->withReportPending()->create();

        $this->postJson("/api/v1/admin/bookings/{$booking->id}/report", [
            'title' => 'v1',
            'file' => UploadedFile::fake()->createWithContent('v1.pdf', '%PDF-1.4 fake pdf content'),
        ])->assertCreated();

        // Replace without asking to notify.
        $response = $this->postJson("/api/v1/admin/bookings/{$booking->id}/report", [
            'title' => 'v2',
            'file' => UploadedFile::fake()->createWithContent('v2.pdf', '%PDF-1.4 fake pdf content'),
            'notify_client' => false,
        ]);

        $this->assertApiSuccess($response, 200)
            ->assertJsonPath('data.title', 'v2')
            ->assertJsonPath('data.file.name', 'v2.pdf');

        $this->assertSame(1, Report::count());
        $this->assertCount(1, Report::sole()->getMedia('report_file'));

        // Only the create notified the client.
        Notification::assertSentTo(Report::sole()->client, ReportReadyNotification::class, 1);
    }

    /*
    |----------------------------------------------------------------------
    | RPT-01 GET /api/v1/admin/reports
    |----------------------------------------------------------------------
    */

    public function test_rpt_01_lists_reports_with_filters_and_scoping(): void
    {
        $this->actingAsAdmin();
        $consultant = $this->createConsultant();

        $matching = Report::factory()->create(['title' => 'Governance review']);
        Report::factory()->create(['title' => 'Compliance audit', 'consultant_id' => $consultant->id]);

        $this->assertSame(2, $this->getJson('/api/v1/admin/reports')->json('meta.total'));
        $this->assertSame(1, $this->getJson('/api/v1/admin/reports?search=Governance')->json('meta.total'));
        $this->assertSame(1, $this->getJson('/api/v1/admin/reports?consultant_id='.$consultant->id)->json('meta.total'));
        $this->assertSame(1, $this->getJson('/api/v1/admin/reports?client_id='.$matching->client_id)->json('meta.total'));
        $this->assertSame(1, $this->getJson('/api/v1/admin/reports?search='.$matching->booking->reference)->json('meta.total'));

        // A consultant only sees his own reports.
        $this->app['auth']->forgetGuards();
        $this->actingAsConsultant($consultant);
        $this->assertSame(1, $this->getJson('/api/v1/admin/reports')->json('meta.total'));
        // consultant_id cannot widen the scope.
        $this->assertSame(1, $this->getJson('/api/v1/admin/reports?consultant_id='.$matching->consultant_id)->json('meta.total'));
    }

    /*
    |----------------------------------------------------------------------
    | RPT-02 / RPT-04 / RPT-05 / RPT-06
    |----------------------------------------------------------------------
    */

    public function test_rpt_02_show_and_the_policy(): void
    {
        $this->actingAsAdmin();
        $report = Report::factory()->create();

        $this->getJson('/api/v1/admin/reports/'.$report->id)
            ->assertOk()
            ->assertJsonPath('data.id', $report->id);

        $this->app['auth']->forgetGuards();
        $this->actingAsConsultant(); // another consultant
        $this->getJson('/api/v1/admin/reports/'.$report->id)->assertForbidden();
    }

    public function test_rpt_04_downloads_the_file_as_an_attachment(): void
    {
        $admin = $this->actingAsAdmin();
        $report = Report::factory()->create();
        $report->addMediaFromString('%PDF-1.4 fake pdf content')->preservingOriginal()->usingFileName('report.pdf')->toMediaCollection('report_file');

        $response = $this->get('/api/v1/admin/reports/'.$report->id.'/download');

        $response->assertOk();
        $response->assertHeader('content-disposition', 'attachment; filename=report.pdf');
        $this->assertSame('%PDF-1.4 fake pdf content', $response->streamedContent());

        // Another consultant → 403.
        $this->app['auth']->forgetGuards();
        $this->actingAsConsultant();
        $this->get('/api/v1/admin/reports/'.$report->id.'/download')->assertForbidden();
    }

    public function test_rpt_05_deletes_the_report_and_its_media(): void
    {
        $this->actingAsAdmin();
        $booking = Booking::factory()->create(['report_status' => 'uploaded']);
        $report = Report::factory()->create(['booking_id' => $booking->id]);
        $media = $report->addMediaFromString('%PDF-1.4 fake pdf content')->preservingOriginal()->usingFileName('report.pdf')->toMediaCollection('report_file');
        $path = $media->getPathRelativeToRoot();

        $this->assertTrue(Storage::disk('local')->exists($path));

        $this->deleteJson('/api/v1/admin/reports/'.$report->id)->assertOk();

        $this->assertSoftDeleted('reports', ['id' => $report->id]);
        $this->assertFalse(Storage::disk('local')->exists($path));
        $this->assertSame('pending', $booking->refresh()->report_status->value);
    }

    public function test_rpt_06_notify_client_resends_the_notification(): void
    {
        $this->actingAsAdmin();
        $report = Report::factory()->create(['client_notified_at' => null]);

        $this->postJson('/api/v1/admin/reports/'.$report->id.'/notify-client')->assertOk();

        Notification::assertSentTo($report->client, ReportReadyNotification::class);
        $this->assertNotNull($report->refresh()->client_notified_at);
    }
}
