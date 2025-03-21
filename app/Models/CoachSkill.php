<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CoachSkill extends Model
{
    use HasFactory;

    protected $fillable = ['coach_id', 'Skill'];
    public $timestamps = false;
    public $incrementing = false; 


}
