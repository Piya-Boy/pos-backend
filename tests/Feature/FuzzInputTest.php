<?php

namespace Tests\Feature;

use App\Pos\Services\CatalogService;
use App\Pos\Sheets\FakeSheetsClient;
use App\Pos\Sheets\SheetsClient;
use App\Pos\Support\AppError;
use Tests\TestCase;

use function App\Pos\Support\boolish;
use function App\Pos\Support\normalizeText;
use function App\Pos\Support\strInput;

// Regression for the fuzz pass: malformed / wrong-type input must surface a
// controlled AppError (envelope), never an uncaught PHP TypeError/Error.
class FuzzInputTest extends TestCase
{
    public function test_str_input_coerces_non_scalars_to_empty(): void
    {
        $this->assertSame('abc', strInput('abc'));
        $this->assertSame('123', strInput(123));
        $this->assertSame('', strInput(['a']));
        $this->assertSame('', strInput(['x' => 1]));
        $this->assertSame('', strInput(null));
    }

    public function test_normalize_text_survives_array_input(): void
    {
        // would throw "Array to string conversion" without the guard
        $this->assertSame('', normalizeText(['a', 'b']));
        $this->assertSame('', normalizeText(['x' => 1], 40));
    }

    public function test_boolish_survives_non_scalar(): void
    {
        $this->assertFalse(boolish(['true']));
        $this->assertTrue(boolish('true'));
        $this->assertTrue(boolish(true));
    }

    public function test_customer_data_with_array_token_is_controlled_error(): void
    {
        $this->app->instance(SheetsClient::class, (new FakeSheetsClient)->seedDefaults());
        $catalog = $this->app->make(CatalogService::class);

        // Controller does strInput() first, so a non-string token arrives as ''.
        $this->expectException(AppError::class);
        $catalog->customerData(strInput(['x' => 1]));
    }
}
