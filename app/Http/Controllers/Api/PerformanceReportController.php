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
                                            ->with([
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

            // إضافة serviceTitle فقط
            $session = $report->session;
            $service = $session->service ?? null;
            $serviceTitle = 'N/A';

            if ($service) {
                if ($service->service_type === 'Mentorship') {
                    $mentorship = $service->mentorship;
                    if ($mentorship) {
                        if (strtolower($mentorship->mentorship_type) === 'mentorship session') {
                            $mentorshipSession = $mentorship->mentorshipSession;
                            $serviceTitle = $mentorshipSession ? $mentorshipSession->session_type : 'Mentorship Session';
                        } elseif (strtolower($mentorship->mentorship_type) === 'mentorship plan') {
                            $mentorshipPlan = $mentorship->mentorshipRequest && $session->mentorshipRequest->requestable
                                ? $session->mentorshipRequest->requestable
                                : $mentorship->mentorshipPlan;
                            $serviceTitle = $mentorshipPlan ? $mentorshipPlan->title : 'Mentorship Plan';
                        } else {
                            $serviceTitle = 'Unknown Mentorship';
                        }
                    } else {
                        $mentorshipPlan = MentorshipPlan::where('service_id', $session->service_id)->first();
                        $serviceTitle = $mentorshipPlan ? $mentorshipPlan->title : 'Mentorship';
                    }
                } elseif ($service->service_type === 'Mock_Interview') {
                    $mockInterview = $service->mockInterview;
                    $serviceTitle = $mockInterview ? $mockInterview->interview_type . ' (' . $mockInterview->interview_level . ')' : 'Mock Interview';
                } elseif ($service->service_type === 'Group_Mentorship') {
                    $groupMentorship = $service->groupMentorship;
                    $serviceTitle = $groupMentorship ? $groupMentorship->title : 'Group Mentorship';
                } elseif ($service->service_type === 'Project_Evaluation') {
                    $serviceTitle = 'Project Evaluation';
                } elseif ($service->service_type === 'CV_Review') {
                    $serviceTitle = 'CV Review';
                } elseif ($service->service_type === 'Linkedin_Optimization') {
                    $serviceTitle = 'LinkedIn Optimization';
                } else {
                    $serviceTitle = str_replace('_', ' ', $service->service_type); // تحويل service_type إلى عنوان
                }
            }

            // إضافة serviceTitle إلى التقرير
            $report->serviceTitle = $serviceTitle;
        });

    return response()->json($reports, 200);
}
                
}
