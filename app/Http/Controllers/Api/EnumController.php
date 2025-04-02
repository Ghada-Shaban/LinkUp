<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class EnumController extends Controller
{
    public function getServiceEnums(Request $request)
    {
        $enums = Cache::remember('enums', 60 * 60 * 24, function () {
            $serviceType = $this->getEnumValues('services', 'service_type');
            $interviewType = $this->getEnumValues('mock_interviews', 'interview_type');
            $interviewLevel = $this->getEnumValues('mock_interviews', 'interview_level');
            $day = $this->getEnumValues('group_mentorships', 'day');

            $mentorshipType = [
                'CV Review',
                'project Assessment',
                'Linkedin Optimization',
                'Mentorship plan'
            ];

            return [
                'service_type' => $serviceType,
                'mentorship_type' => $mentorshipType,
                'interview_type' => $interviewType,
                'interview_level' => $interviewLevel,
                'day' => $day,
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
