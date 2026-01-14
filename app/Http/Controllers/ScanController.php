<?php

namespace App\Http\Controllers;

use App\Models\Scan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class ScanController extends Controller
{
    public function index(Request $request)
    {
        $query = Scan::query();

        $risk = $request->get('risk', 'all');
        if ($risk !== 'all') {
            $query->where('risk_level', $risk);
        }

        $days = $request->get('days', '30');
        if ($days === 'today') {
            $query->whereDate('created_at', Carbon::today());
        } elseif ($days === '7') {
            $query->where('created_at', '>=', Carbon::now()->subDays(7));
        } elseif ($days === '30') {
            $query->where('created_at', '>=', Carbon::now()->subDays(30));
        }

        if ($request->filled('search')) {
            $query->where('filename', 'like', '%' . $request->search . '%');
        }

        return response()->json(
            $query->latest()->paginate($request->get('per_page', 5))
        );
    }

    public function store(Request $request)
    {
        try {
            $request->validate(['file' => 'required|file']);

            $file = $request->file('file');
            $filename = $file->getClientOriginalName();

            if (!str_ends_with(strtolower($filename), '.py')) {
                return response()->json(['error' => 'Only Python files allowed'], 400);
            }

            $path = $file->storeAs('scans', $filename, 'private');
            $fullPath = storage_path('app/private/' . $path);

            if (!file_exists($fullPath)) {
                return response()->json(['error' => 'File not found'], 500);
            }

            $content = Storage::disk('private')->get($path);

            $process = Process::run([
                'python3',
                base_path('extract_metrics.py'),
                $fullPath
            ]);

            if ($process->failed()) {
                return response()->json(['error' => 'Metric extraction failed'], 500);
            }

            $metrics = json_decode($process->output(), true);
            if (!$metrics || isset($metrics['error'])) {
                return response()->json(['error' => 'Invalid metrics'], 400);
            }

            $detSeverity = (int) ($metrics['deterministic_severity'] ?? 0);
            $detClean = (bool) ($metrics['deterministic_clean'] ?? false);
            $complexity = (int) ($metrics['radon_total_complexity'] ?? 0);
            $lintCount = (int) ($metrics['pylint_msgs_count'] ?? 0);

            $mlConfidence = 50;
            $prediction = null;

            try {
                $res = Http::timeout(30)->post(
                    'https://web-production-3dfc8.up.railway.app/predict',
                    $metrics
                );
                if ($res->successful()) {
                    $prediction = $res->json();
                    $mlConfidence = $prediction['confidence']
                        ?? ($prediction['is_bug'] ? 85 : 15);
                }
            } catch (\Exception $e) {}

            if ($detSeverity >= 80) {
                $risk = 'high';
                $prob = max($detSeverity, $mlConfidence);
            } elseif (
                !$detClean ||
                $complexity > 10 ||
                $lintCount > 3 ||
                ($mlConfidence >= 55 && $mlConfidence <= 70)
            ) {
                $risk = 'medium';
                $prob = max(45, min(65, max($detSeverity, $mlConfidence)));
            } else {
                $risk = 'low';
                $prob = min(25, $mlConfidence);
            }

            $scan = Scan::create([
                'filename' => $filename,
                'file_path' => $path,
                'metrics' => $metrics,
                'result' => $prediction,
                'status' => $prediction ? 'completed' : 'partial',
                'defect_probability' => $prob,
                'risk_level' => $risk,
            ]);

            return response()->json([
                'scan' => $scan,
                'file_content' => $content
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Upload failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function show(Scan $scan)
    {
        $content = null;
        if ($scan->file_path && Storage::disk('private')->exists($scan->file_path)) {
            $content = Storage::disk('private')->get($scan->file_path);
        }

        return response()->json([
            'scan' => $scan,
            'file_content' => $content
        ]);
    }

    public function destroy(Scan $scan)
    {
        if ($scan->file_path && Storage::disk('private')->exists($scan->file_path)) {
            Storage::disk('private')->delete($scan->file_path);
        }

        $scan->delete();
        return response()->json(['message' => 'Deleted']);
    }

    public function getLatency()
    {
        try {
            $start = microtime(true);
            $res = Http::timeout(5)->post(
                'https://web-production-3dfc8.up.railway.app/predict',
                [
                    'radon_total_complexity' => 5,
                    'radon_num_items' => 3,
                    'pylint_msgs_count' => 2,
                    'pylint_rc' => 0,
                    'bandit_issues_count' => 0,
                    'bandit_rc' => 0,
                ]
            );

            return response()->json([
                'latency' => round((microtime(true) - $start) * 1000),
                'status' => $res->successful() ? 'online' : 'offline'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'latency' => 0,
                'status' => 'offline'
            ]);
        }
    }
}
