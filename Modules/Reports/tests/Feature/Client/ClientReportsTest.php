<?php

namespace Modules\Reports\Tests\Feature\Client;

use Modules\Reports\Models\Report;
use Tests\TestCase;

class ClientReportsTest extends TestCase
{
    /*
    |----------------------------------------------------------------------
    | CLI-RPT-01 GET /api/v1/client/reports
    |----------------------------------------------------------------------
    */

    public function test_cli_rpt_01_lists_only_the_clients_reports_with_search(): void
    {
        $client = $this->actingAsClient();

        $mine = Report::factory()->create(['title' => 'Governance review', 'client_id' => $client->id]);
        Report::factory()->create(['title' => 'Governance review']); // another client's

        $response = $this->getJson('/api/v1/client/reports');

        $this->assertPaginated($response);
        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame($mine->id, $response->json('data.0.id'));

        $this->assertSame(1, $this->getJson('/api/v1/client/reports?search=Governance')->json('meta.total'));
        $this->assertSame(0, $this->getJson('/api/v1/client/reports?search=Compliance')->json('meta.total'));
    }

    /*
    |----------------------------------------------------------------------
    | CLI-RPT-02 GET /api/v1/client/reports/{report}
    |----------------------------------------------------------------------
    */

    public function test_cli_rpt_02_show_and_404_for_another_clients_report(): void
    {
        $client = $this->actingAsClient();

        $mine = Report::factory()->create(['client_id' => $client->id]);
        $theirs = Report::factory()->create();

        $this->getJson('/api/v1/client/reports/'.$mine->id)
            ->assertOk()
            ->assertJsonPath('data.id', $mine->id)
            ->assertJsonPath('data.download_url', route('client.reports.download', $mine));

        $this->getJson('/api/v1/client/reports/'.$theirs->id)->assertNotFound();
    }

    /*
    |----------------------------------------------------------------------
    | CLI-RPT-03 GET /api/v1/client/reports/{report}/download
    |----------------------------------------------------------------------
    */

    public function test_cli_rpt_03_downloads_and_marks_first_downloaded_at(): void
    {
        $client = $this->actingAsClient();

        $report = Report::factory()->create(['client_id' => $client->id]);
        $report->addMediaFromString('%PDF-1.4 fake pdf content')->preservingOriginal()->usingFileName('report.pdf')->toMediaCollection('report_file');

        $response = $this->get('/api/v1/client/reports/'.$report->id.'/download');

        $response->assertOk();
        $response->assertHeader('content-disposition', 'attachment; filename=report.pdf');
        $this->assertSame('%PDF-1.4 fake pdf content', $response->streamedContent());
        $this->assertNotNull($report->refresh()->first_downloaded_at);

        // A second download does not move the timestamp.
        $first = $report->first_downloaded_at;
        $this->travel(2)->hours();
        $this->get('/api/v1/client/reports/'.$report->id.'/download')->assertOk();
        $this->assertTrue($first->equalTo($report->refresh()->first_downloaded_at));
    }

    public function test_cli_rpt_03_another_clients_report_returns_404(): void
    {
        $this->actingAsClient();
        $report = Report::factory()->create();
        $report->addMediaFromString('%PDF-1.4 fake pdf content')->preservingOriginal()->toMediaCollection('report_file');

        $this->get('/api/v1/client/reports/'.$report->id.'/download')->assertNotFound();
    }
}
