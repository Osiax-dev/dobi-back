<?php

namespace Tests\Unit;

use App\Support\SubmissionValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SubmissionValidatorTypesTest extends TestCase
{
    private function run_(string $type, mixed $answer, array $settings = [], bool $required = false): array
    {
        $snapshot = ['sections' => [], 'questions' => [['id' => 1, 'section_id' => null, 'type' => $type, 'required' => $required, 'settings' => $settings, 'options' => [], 'rules' => []]]];

        return SubmissionValidator::validate($snapshot, [1 => $answer]);
    }

    public static function valid(): array
    {
        return [
            'entier texte' => ['integer', '42', [], 42],
            'entier négatif' => ['integer', -7, [], -7],
            'décimal virgule' => ['decimal', '12,5', [], 12.5],
            'décimal limité' => ['decimal', '3.14', ['decimals' => 2], 3.14],
            'heure' => ['time', '08:30', [], '08:30'],
            'heure avec secondes' => ['time', '08:30:15', [], '08:30'],
            'date et heure' => ['datetime', '2026-11-02T14:05', [], '2026-11-02T14:05'],
            'date et heure espace' => ['datetime', '2026-11-02 14:05', [], '2026-11-02T14:05'],
            'e-mail' => ['short_text', 'a@b.com', ['format' => 'email'], 'a@b.com'],
            'téléphone' => ['short_text', '+229 97 00 00 00', ['format' => 'phone'], '+229 97 00 00 00'],
            'url' => ['short_text', 'https://dobi.app/x', ['format' => 'url'], 'https://dobi.app/x'],
            'date avec bornes' => ['date', '2026-06-15', ['min' => '2026-01-01', 'max' => '2026-12-31'], '2026-06-15'],
            'heure avec bornes' => ['time', '10:00', ['min' => '08:00', 'max' => '18:00'], '10:00'],
        ];
    }

    #[DataProvider('valid')]
    public function test_valeurs_valides(string $type, mixed $answer, array $settings, mixed $expected): void
    {
        $r = $this->run_($type, $answer, $settings);
        $this->assertSame([], $r['errors']);
        $this->assertSame($expected, $r['clean']['1']);
    }

    public static function invalid(): array
    {
        return [
            'entier décimal' => ['integer', '3.5', []],
            'entier hors borne' => ['integer', 5, ['max' => 3]],
            'décimal trop précis' => ['decimal', '3.141', ['decimals' => 2]],
            'décimal texte' => ['decimal', 'abc', []],
            'heure invalide' => ['time', '25:00', []],
            'heure hors borne' => ['time', '07:59', ['min' => '08:00']],
            'date et heure invalide' => ['datetime', '2026-13-01T10:00', []],
            'e-mail invalide' => ['short_text', 'pas-un-mail', ['format' => 'email']],
            'téléphone invalide' => ['short_text', 'abc', ['format' => 'phone']],
            'url sans http' => ['short_text', 'javascript:alert(1)', ['format' => 'url']],
            'texte trop court' => ['short_text', 'a', ['min_length' => 3]],
            'date avant minimum' => ['date', '2025-12-31', ['min' => '2026-01-01']],
            'position hors limite' => ['geolocation', ['lat' => 91, 'lng' => 0], []],
            'position incomplète' => ['geolocation', ['lat' => 5], []],
        ];
    }

    #[DataProvider('invalid')]
    public function test_valeurs_refusees(string $type, mixed $answer, array $settings): void
    {
        $r = $this->run_($type, $answer, $settings);
        $this->assertArrayHasKey('1', $r['errors']);
        $this->assertArrayNotHasKey('1', $r['clean']);
    }

    public function test_choix_autre_en_choix_unique(): void
    {
        $snapshot = ['sections' => [], 'questions' => [[
            'id' => 1, 'section_id' => null, 'type' => 'single_choice', 'required' => true,
            'settings' => ['allow_other' => true], 'options' => [['value' => 'opt_1', 'label' => 'Option 1']], 'rules' => [],
        ]]];
        $r = SubmissionValidator::validate($snapshot, [1 => '__dobi_other__:Ma réponse libre']);
        $this->assertSame([], $r['errors']);
        $this->assertSame(['1' => '__dobi_other__:Ma réponse libre'], $r['clean']);
    }

    public function test_choix_autre_en_choix_multiple(): void
    {
        $snapshot = ['sections' => [], 'questions' => [[
            'id' => 1, 'section_id' => null, 'type' => 'multiple_choice', 'required' => true,
            'settings' => ['allow_other' => true], 'options' => [['value' => 'opt_1', 'label' => 'Option 1']], 'rules' => [],
        ]]];
        $r = SubmissionValidator::validate($snapshot, [1 => ['opt_1', '__dobi_other__:Ma réponse libre']]);
        $this->assertSame([], $r['errors']);
        $this->assertSame(['opt_1', '__dobi_other__:Ma réponse libre'], $r['clean']['1']);
    }

    public function test_choix_autre_sans_precision_est_refuse(): void
    {
        $snapshot = ['sections' => [], 'questions' => [[
            'id' => 1, 'section_id' => null, 'type' => 'single_choice', 'required' => true,
            'settings' => ['allow_other' => true], 'options' => [], 'rules' => [],
        ]]];
        $r = SubmissionValidator::validate($snapshot, [1 => '__dobi_other__:']);
        $this->assertArrayHasKey('1', $r['errors']);
        $this->assertArrayNotHasKey('1', $r['clean']);
    }

    public function test_precision_de_la_geolocalisation(): void
    {
        $r = $this->run_('geolocation', ['lat' => 9.3375123, 'lng' => 2.6301987, 'extra' => 'ignoré'], ['precision' => 2]);
        $this->assertSame(['lat' => 9.34, 'lng' => 2.63], $r['clean']['1']);
    }

    public function test_operateurs_de_condition(): void
    {
        $this->assertTrue(SubmissionValidator::evaluate(18, 'gte', '18'));
        $this->assertFalse(SubmissionValidator::evaluate(17, 'gte', '18'));
        $this->assertTrue(SubmissionValidator::evaluate(5, 'lte', 5));
        $this->assertTrue(SubmissionValidator::evaluate('x', 'answered', null));
        $this->assertFalse(SubmissionValidator::evaluate(null, 'answered', null));
        $this->assertTrue(SubmissionValidator::evaluate(null, 'not_answered', null));
        $this->assertTrue(SubmissionValidator::evaluate(['opt_1', 'opt_2'], 'contains', 'opt_2'));
    }
}
