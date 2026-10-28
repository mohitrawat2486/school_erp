<?php

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use setasign\Fpdi\Fpdi;

class ReportCardPdf
{
    public function render(iterable $results): string
    {
        $results = collect($results);
        if ($results->isEmpty()) {
            throw new \InvalidArgumentException('Choose at least one published report.');
        }
        $folder = 'result-pdfs/tmp/'.Str::uuid();
        $disk = Storage::disk('local');
        $disk->makeDirectory($folder);
        $cache = storage_path('framework/report-pdf');
        File::ensureDirectoryExists($cache);
        $merged = new Fpdi;
        $merged->SetAutoPageBreak(false);
        $merged->SetTitle('Published Report Cards');
        try {
            foreach ($results->chunk((int) config('reports.pdf_chunk_size', 5)) as $index => $chunk) {
                (new Collection($chunk->all()))->loadMissing(['subjects', 'resultBatch']);
                $cards = [];
                foreach ($chunk as $result) {
                    $result->loadMissing(['subjects', 'resultBatch']);
                    $school = $result->resultBatch->calculation_context['report_school'] ?? [];
                    $logo = app(ReportCardContext::class)->logo($school);
                    if ($logo && str_starts_with($logo, 'data:image/webp;')) {
                        $image = imagecreatefromstring(base64_decode(explode(',', $logo, 2)[1], true));
                        if (! $image) {
                            throw new \RuntimeException('Unable to decode captured report logo.');
                        }ob_start();
                        imagepng($image);
                        $png = ob_get_clean();
                        imagedestroy($image);
                        $logo = 'data:image/png;base64,'.base64_encode($png);
                    }
                    $cards[] = ['result' => $result, 'subjects' => $result->subjects->sortBy('subject_id'), 'school' => $school, 'logo' => $logo, 'identity' => $result->report_context ?? []];
                }
                $options = new Options(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isJavascriptEnabled' => false, 'defaultFont' => 'DejaVu Sans', 'isFontSubsettingEnabled' => true, 'tempDir' => $cache, 'fontCache' => $cache, 'chroot' => storage_path('app')]);
                $dompdf = new Dompdf($options);
                $dompdf->setPaper('A4', 'portrait');
                $dompdf->loadHtml(view('report-cards.pdf', compact('cards'))->render(), 'UTF-8');
                $dompdf->render();
                $path = $folder.'/'.$index.'.pdf';
                $disk->put($path, $dompdf->output());
                unset($dompdf);
                $pages = $merged->setSourceFile($disk->path($path));
                for ($page = 1; $page <= $pages; $page++) {
                    $template = $merged->importPage($page);
                    $size = $merged->getTemplateSize($template);
                    $merged->AddPage($size['orientation'], [$size['width'], $size['height']]);
                    $merged->useImportedPage($template);
                }
            }

            return $merged->Output('S');
        } finally {
            $merged->cleanUp();
            $disk->deleteDirectory($folder);
        }
    }
}
