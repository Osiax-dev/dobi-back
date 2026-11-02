<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Study;
use App\Support\AuditLogger;
use App\Support\ResponseFilters;
use App\Support\XlsxWriter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ExportController extends Controller
{
    /** Export des réponses : ?format=csv (défaut) ou xlsx, avec les mêmes filtres que les statistiques. */
    public function index(Request $request, Study $study): Response
    {
        $this->authorize('viewResults', $study);
        $request->validate(ResponseFilters::rules() + ['format' => ['nullable', 'in:csv,xlsx']]);

        $format = $request->query('format', 'csv');
        $questions = $study->currentVersion?->snapshot['questions'] ?? [];
        $withIdentity = $study->privacy_mode === 'identified' && $request->user()->can('viewIdentity', $study);

        $header = ['id_reponse', 'date', 'version'];
        foreach ($questions as $q) {
            $header[] = 'Q'.$q['id'].' - '.$q['label'];
        }
        if ($withIdentity) {
            array_push($header, 'nom', 'email', 'telephone');
        }

        AuditLogger::log($request->user(), 'study.exported', $study, $study->organization_id, ['identity' => $withIdentity, 'format' => $format]);

        $isCsv = $format === 'csv';
        $rows = function () use ($request, $study, $questions, $withIdentity, $isCsv) {
            $query = ResponseFilters::apply($study->responses(), $request)->with($withIdentity ? ['answers', 'participant'] : ['answers']);
            foreach ($query->lazyById(500) as $submission) {
                $answers = $submission->answers->keyBy('question_id');
                $row = [$submission->uuid, $submission->submitted_at->format('Y-m-d H:i:s'), $submission->study_version_id];
                foreach ($questions as $q) {
                    $row[] = $this->cell($q, $answers->get($q['id'])?->value, $isCsv);
                }
                if ($withIdentity) {
                    $p = $submission->participant;
                    array_push($row, ...($p ? [$this->text($p->full_name, $isCsv), $this->text($p->email, $isCsv), $this->text((string) $p->phone, $isCsv)] : ['', '', '']));
                }
                yield $row;
            }
        };

        $base = 'etude-'.$study->slug.'-'.now()->format('Ymd-His');

        if ($format === 'xlsx') {
            $path = tempnam(sys_get_temp_dir(), 'dobi');
            XlsxWriter::write($path, (function () use ($header, $rows) {
                yield $header;
                yield from $rows();
            })(), 'Réponses');

            return response()->download($path, $base.'.xlsx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])->deleteFileAfterSend(true);
        }

        // CSV UTF-8 avec BOM, séparateur « ; » : s'ouvre directement dans Excel.
        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $header, ';');
            foreach ($rows() as $row) {
                fputcsv($out, $row, ';');
            }
            fclose($out);
        }, $base.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function cell(array $q, mixed $value, bool $csv): string|int|float
    {
        if ($value === null) {
            return '';
        }

        $labels = [];
        foreach ($q['options'] as $o) {
            $labels[$o['value']] = $o['label'];
        }

        if (is_bool($value)) {
            return $value ? 'Oui' : 'Non';
        }
        if (is_array($value)) {
            return $this->text(implode(' | ', array_map(fn ($v) => $labels[$v] ?? (string) $v, $value)), $csv);
        }
        if (is_string($value)) {
            return $this->text($labels[$value] ?? $value, $csv);
        }

        return $value; // nombres : restent des nombres dans Excel
    }

    /** Dans un CSV, neutralise l'injection de formules (=, +, -, @) à l'ouverture dans Excel. */
    private function text(string $text, bool $csv): string
    {
        return $csv && preg_match('/^[=+\-@\t\r]/', $text) ? "'".$text : $text;
    }
}
