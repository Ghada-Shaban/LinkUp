<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class EnumController extends Controller
{
    public function getServiceEnums(Request $request)
    {
        if ($request->has('refresh')) {
            Cache::forget('enums');
        }

        $enums = Cache::remember('enums', 60 * 60 * 24, function () {
            try {
                $serviceType = $this->getEnumValues('services', 'service_type');
                $interviewType = $this->getEnumValues('mock_interviews', 'interview_type');
                $interviewLevel = $this->getEnumValues('mock_interviews', 'interview_level');
                $day = $this->getEnumValues('group_mentorships', 'day');
                $mentorshipType = $this->getEnumValues('mentorships', 'mentorship_type');
                $sessionType = $this->getEnumValues('mentorship_sessions', 'session_type');
                $role = $this->getEnumValues('mentorships', 'role');
                $careerPhase = $this->getEnumValues('mentorships', 'career_phase');

                return [
                    'service_type' => $serviceType,
                    'mentorship_type' => $mentorshipType,
                    'session_type' => $sessionType,
                    'interview_type' => $interviewType,
                    'interview_level' => $interviewLevel,
                    'day' => $day,
                    'role' => $role, 
                    'career_phase' => $careerPhase,
                ];
            } catch (\Exception $e) {
                return [
                    'service_type' => [],
                    'mentorship_type' => [],
                    'session_type' => [],
                    'interview_type' => [],
                    'interview_level' => [],
                    'day' => [],
                    'role' => [], 
                    'career_phase' => [],
                ];
            }
        });

        return response()->json([
            'enums' => $enums
        ]);
    }

    private function getEnumValues($table, $column)
    {
        try {
            if (!Schema::hasTable($table)) {
                return [];
            }

            if (!Schema::hasColumn($table, $column)) {
                return [];
            }

            $columnInfo = DB::select("SHOW COLUMNS FROM {$table} WHERE Field = '{$column}'");

            if (empty($columnInfo)) {
                return [];
            }

            $type = $columnInfo[0]->Type;
            
            if (!preg_match("/^enum\((.*)\)$/", $type, $matches)) {
                return [];
            }
            
            $enumValues = array_map(function ($value) {
                return trim($value, "'");
            }, explode(',', $matches[1]));

            return $enumValues;
        } catch (\Exception $e) {
            return [];
        }
    }
}
