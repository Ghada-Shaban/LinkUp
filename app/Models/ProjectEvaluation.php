<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProjectEvaluation extends Model
{
    use HasFactory;
    protected $table = 'project_evaluations';
    public $timestamps = false;
    protected $fillable = [
        'service_id',
        'submission_date',
        'project_link',
        'project_title',
        'review_status',
        'description'
    ];
    public function service()  
{  
    return $this->belongsTo(Service::class, 'service_id');  
}
}
