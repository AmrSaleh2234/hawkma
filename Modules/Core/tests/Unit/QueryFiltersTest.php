<?php

namespace Modules\Core\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\QueryFilters;
use Tests\TestCase;

class QueryFiltersTestUser extends Model
{
    protected $table = 'users';

    protected $guarded = [];
}

class QueryFiltersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('users')->insert([
            ['name' => 'Ahmad Ali', 'email' => 'ahmad@example.com', 'password' => 'x', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Sara Omar', 'email' => 'sara@example.com', 'password' => 'x', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Omar Khaled', 'email' => 'omar@example.com', 'password' => 'x', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function test_search_filters_by_searchable_columns(): void
    {
        $request = Request::create('/?search=omar', 'GET');

        $results = QueryFilters::apply(QueryFiltersTestUser::query(), $request, ['name', 'email'], ['id'])->get();

        $this->assertSame(2, $results->count());
        $this->assertTrue($results->contains('email', 'sara@example.com'));
        $this->assertTrue($results->contains('email', 'omar@example.com'));
    }

    public function test_allowed_sort_is_applied(): void
    {
        $request = Request::create('/?sort=name', 'GET');

        $results = QueryFilters::apply(QueryFiltersTestUser::query(), $request, [], ['name'])->get();

        $this->assertSame('Ahmad Ali', $results->first()->name);
    }

    public function test_descending_sort_with_dash_prefix(): void
    {
        $request = Request::create('/?sort=-name', 'GET');

        $results = QueryFilters::apply(QueryFiltersTestUser::query(), $request, [], ['name'])->get();

        $this->assertSame('Sara Omar', $results->first()->name);
    }

    public function test_forbidden_sort_is_ignored_and_default_is_used(): void
    {
        $request = Request::create('/?sort=-password', 'GET');

        $query = QueryFilters::apply(QueryFiltersTestUser::query(), $request, [], ['name'], '-id');

        $this->assertStringContainsString('order by "id" desc', $query->toSql());
    }

    public function test_per_page_is_limited_to_100(): void
    {
        $request = Request::create('/?per_page=500', 'GET');

        $this->assertSame(100, QueryFilters::perPage($request));
    }

    public function test_per_page_defaults_to_15_for_invalid_values(): void
    {
        $this->assertSame(15, QueryFilters::perPage(Request::create('/', 'GET')));
        $this->assertSame(15, QueryFilters::perPage(Request::create('/?per_page=0', 'GET')));
        $this->assertSame(50, QueryFilters::perPage(Request::create('/?per_page=50', 'GET')));
    }
}
