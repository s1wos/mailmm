<?php

namespace Tests\Unit;

use App\Services\EmailGeneratorService;
use Illuminate\Support\Str;
use Tests\TestCase;

class EmailGeneratorTest extends TestCase
{
    public function test_production_word_lists_include_mint_fox_and_offer_at_least_65536_pairs(): void
    {
        $this->assertIsArray(config('mailbox_words'));
        foreach (['first' => 'mint', 'second' => 'fox'] as $position => $example) {
            $words = config('mailbox_words.'.$position);
            $this->assertContains($example, $words);
            $this->assertGreaterThanOrEqual(256, count($words));
            $this->assertCount(count($words), array_unique($words));
            $this->assertMatchesRegularExpression('/\A[a-z]+(?: [a-z]+)*\z/', implode(' ', $words));
        }
    }

    public function test_only_whole_fitting_word_pairs_are_offered_and_exhausted_once(): void
    {
        config(['mailbox_words.first' => ['lavender', 'mint'], 'mailbox_words.second' => ['sparrow', 'fox']]);
        $domain = str_repeat(str_repeat('a', 63).'.', 3).str_repeat('b', 53);
        $generator = new EmailGeneratorService;
        $this->assertSame(8, $generator->localPartLimit($domain));
        $this->assertSame(['random', 'words'], $generator->formatsFor($domain));
        $this->assertSame(['mint.fox@'.$domain], iterator_to_array($generator->candidates($domain, 'words')));
        $this->assertSame(['random'], $generator->formatsFor($domain.'a'));
        $this->assertSame([], iterator_to_array($generator->candidates($domain.'a', 'words')));
    }

    public function test_both_formats_offer_five_distinct_complete_addresses(): void
    {
        $generator = new EmailGeneratorService;
        $this->assertSame(64, $generator->localPartLimit('a.test'));
        foreach (['random' => '[a-z0-9]{14}', 'words' => '[a-z]+\.[a-z]+'] as $format => $pattern) {
            $addresses = iterator_to_array($generator->candidates('a.test', $format));
            $this->assertCount(5, $addresses);
            $this->assertCount(5, array_unique($addresses));
            foreach ($addresses as $address) {
                $this->assertMatchesRegularExpression('/\A'.$pattern.'@a\.test\z/', $address);
            }
        }
    }

    public function test_one_byte_random_names_terminate_with_distinct_candidates_even_if_random_strings_repeat(): void
    {
        $domain = str_repeat(str_repeat('a', 63).'.', 3).str_repeat('b', 60);
        $generator = new EmailGeneratorService;
        Str::createRandomStringsUsing(fn (int $length) => str_repeat('A', $length));
        try {
            $addresses = iterator_to_array($generator->candidates($domain, 'random'));
        } finally {
            Str::createRandomStringsNormally();
        }
        $this->assertCount(5, array_unique($addresses));
        $this->assertSame('a@'.$domain, $addresses[0]);
        foreach ($addresses as $address) {
            $this->assertSame(254, strlen($address));
            $this->assertMatchesRegularExpression('/\A[a-z0-9]@/', $address);
        }
    }
}
