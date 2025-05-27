<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NewSession;
use App\Models\PerformanceReport;
use Illuminate\Http\Request;

class PerformanceReportController extends Controller
{
    
    public function submitPerformanceReport(Request $request, $sessionId)
    {
        $coach = auth()->user();
        $session = NewSession::findOrFail($sessionId);

        if ($session->coach_id !== $coach->User_ID || !$session->isCompleted()) {
            return response()->json(['message' => 'Unauthorized or session not completed'], 403);
        }

        $request->validate([
            'overall_rating' => 'required|integer|min:1|max:5',
            'strengths' => 'required|string|max:1000',
            'weaknesses' => 'required|string|max:1000',
            'comments' => 'nullable|string|max:1000',
        ]);

        $report = new PerformanceReport();
        $report->session_id = $sessionId;
        $report->coach_id = $coach->User_ID;
        $report->trainee_id = $session->trainee_id;
        $report->overall_rating = $request->overall_rating;
        $report->strengths = $request->strengths;
        $report->weaknesses = $request->weaknesses;
        $report->comments = $request->comments ?? null;
        $report->save();

        return response()->json(['message' => 'Performance report submitted successfully'], 200);
    }

   
    public function getPerformanceReports(Request $request)
    {
        $trainee = auth()->user();
        $reports = PerformanceReport::where('trainee_id', $trainee->User_ID)
            ->with(['coach' => function ($query) {
            $query->select('User_ID', 'full_name','profile_photo_url'); 
        }])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($reports, 200);
    }
}
