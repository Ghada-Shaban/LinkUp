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
        // إذا كان فيه query parameter اسمه refresh، نفضي الـ Cache
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

                return [
                    'service_type' => $serviceType,
                    'mentorship_type' => $mentorshipType,
                    'session_type' => $sessionType,
                    'interview_type' => $interviewType,
                    'interview_level' => $interviewLevel,
                    'day' => $day,
                ];
            } catch (\Exception $e) {
                \Log::error('Error fetching enums: ' . $e->getMessage());
                return [
                    'service_type' => [],
                    'mentorship_type' => [],
                    'session_type' => [],
                    'interview_type' => [],
                    'interview_level' => [],
                    'day' => [],
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
            // التحقق من وجود الجدول
            if (!Schema::hasTable($table)) {
                \Log::warning("Table {$table} does not exist.");
                return [];
            }

            // التحقق من وجود الحقل
            if (!Schema::hasColumn($table, $column)) {
                \Log::warning("Column {$column} does not exist in table {$table}.");
                return [];
            }

            // جلب نوع الحقل
            $columnInfo = DB::select("SHOW COLUMNS FROM {$table} WHERE Field = '{$column}'");

            if (empty($columnInfo)) {
                \Log::warning("Column {$column} not found in table {$table}.");
                return [];
            }

            $type = $columnInfo[0]->Type;

            // التحقق إن الحقل من نوع enum
            if (!preg_match("/^enum\((.*)\)$/", $type, $matches)) {
                \Log::warning("Column {$column} in table {$table} is not of type ENUM.");
                return [];
            }

            // استخراج القيم
            $enumValues = array_map(function ($value) {
                return trim($value, "'");
            }, explode(',', $matches[1]));

            return $enumValues;
        } catch (\Exception $e) {
            \Log::error("Error fetching enum values for {$table}.{$column}: " . $e->getMessage());
            return [];
        }
    }
}
