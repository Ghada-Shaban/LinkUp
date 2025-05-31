<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NewSession;
use App\Models\Service;
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
        ->with([
            'coach' => function ($query) {
                $query->select('User_ID', 'full_name', 'photo');
            },
            'session' => function ($query) {
                $query->select('new_session_id', 'date_time', 'duration', 'service_id')
                    ->with([
                        'service' => function ($query) {
                            $query->select('service_id', 'service_type')
                                ->with([
                                    'mentorship' => function ($query) {
                                        $query->select('service_id', 'mentorship_type')
                                            ->when(true, function ($query) {
                                                $query->with([
                                                    'mentorshipSession' => function ($query) {
                                                        $query->select('service_id', 'session_type');
                                                    },
                                                    'mentorshipPlan' => function ($query) {
                                                        $query->select('service_id', 'title');
                                                    },
                                                    'mentorshipRequest' => function ($query) {
                                                        $query->select('id', 'requestable_id', 'requestable_type')
                                                            ->with('requestable');
                                                    }
                                                ]);
                                            });
                                    },
                                    'mockInterview' => function ($query) {
                                        $query->select('service_id', 'interview_type', 'interview_level');
                                    },
                                    'groupMentorship' => function ($query) {
                                        $query->select('service_id', 'title');
                                    }
                                ]);
                        }
                    ]);
            }
        ])
        ->orderBy('created_at', 'desc')
        ->get()
        ->each(function ($report) {
            $report->coach->makeHidden(['profile_photo_url', 'photo_url']);

            // إضافة serviceTitle وتنظيف العلاقات
            $session = $report->session;
            $service = $session->service ?? null;
            $serviceTitle = 'N/A';
            $relevantRelation = null;

            if ($service) {
                // تحديد العلاقة المناسبة بناءً على service_type
                $mentorshipTypes = ['Mentorship', 'Project_Evaluation', 'CV_Review', 'Linkedin_Optimization'];
                if (in_array($service->service_type, $mentorshipTypes)) {
                    $mentorship = $service->mentorship;
                    if ($mentorship && strtolower($mentorship->mentorship_type) === 'mentorship session') {
                        $mentorshipSession = $mentorship->mentorshipSession;
                        $serviceTitle = $mentorshipSession ? $mentorshipSession->session_type : str_replace('_', ' ', $service->service_type);
                        $relevantRelation = $mentorship ? ['mentorship' => $mentorship->toArray()] : null;
                    } elseif ($mentorship && strtolower($mentorship->mentorship_type) === 'mentorship plan') {
                        $mentorshipPlan = $mentorship->mentorshipRequest && $session->mentorshipRequest->requestable
                            ? $session->mentorshipRequest->requestable
                            : $mentorship->mentorshipPlan;
                        $serviceTitle = $mentorshipPlan ? $mentorshipPlan->title : 'Mentorship Plan';
                        $relevantRelation = $mentorship ? ['mentorship' => $mentorship->toArray()] : null;
                    } else {
                        $mentorshipPlan = MentorshipPlan::where('service_id', $session->service_id)->first();
                        $serviceTitle = $mentorshipPlan ? $mentorshipPlan->title : str_replace('_', ' ', $service->service_type);
                        $relevantRelation = $mentorshipPlan ? ['mentorship' => ['mentorship_plan' => $mentorshipPlan->toArray()]] : null;
                    }
                } elseif ($service->service_type === 'Mock_Interview') {
                    $mockInterview = $service->mockInterview;
                    $serviceTitle = $mockInterview ? $mockInterview->interview_type . ' (' . $mockInterview->interview_level . ')' : 'Mock Interview';
                    $relevantRelation = $mockInterview ? ['mock_interview' => $mockInterview->toArray()] : null;
                } elseif ($service->service_type === 'Group_Mentorship') {
                    $groupMentorship = $service->groupMentorship;
                    $serviceTitle = $groupMentorship ? $groupMentorship->title : 'Group Mentorship';
                    $relevantRelation = $groupMentorship ? ['group_mentorship' => $groupMentorship->toArray()] : null;
                } else {
                    $serviceTitle = str_replace('_', ' ', $service->service_type);
                    $relevantRelation = null;
                }

                // تنظيف حقل service لإظهار العلاقة المناسبة فقط
                $serviceData = [
                    'service_id' => $service->service_id,
                    'service_type' => $service->service_type,
                ];
                if ($relevantRelation) {
                    $serviceData = array_merge($serviceData, $relevantRelation);
                }
                $report->session->service = $serviceData;
            }

            // إضافة serviceTitle إلى التقرير
            $report->serviceTitle = $serviceTitle;
        });

    return response()->json($reports, 200);
}
                 
                
}
