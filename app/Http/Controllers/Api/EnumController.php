<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class EnumController extends Controller
{
    public function getEnums(Request $request)
    {
        $enums = Cache::remember('enums', 60 * 60 * 24, function () {
            $serviceTypes = $this->getEnumValues('services', 'service_type');
            $interviewTypes = $this->getEnumValues('mock_interviews', 'interview_type');
            $interviewLevels = $this->getEnumValues('mock_interviews', 'interview_level');
            $days = $this->getEnumValues('group_mentorships', 'day');

            $mentorshipTypes = [
                'CV Review',
                'project Assessment',
                'Linkedin Optimization',
                'Mentorship plan'
            ];

            return [
                'service_types' => $serviceTypes,
                'mentorship_types' => $mentorshipTypes,
                'interview_types' => $interviewTypes,
                'interview_levels' => $interviewLevels,
                'days' => $days,
            ];
        });

        return response()->json([
            'enums' => $enums
        ]);
    }

    private function getEnumValues($table, $column)
    {
        $type = DB::select("SHOW COLUMNS FROM {$table} WHERE Field = '{$column}'")[0]->Type;
        preg_match("/^enum\((.*)\)$/", $type, $matches);
        $enumValues = array_map(function ($value) {
            return trim($value, "'");
        }, explode(',', $matches[1]));

        return $enumValues;
    }
}