<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;



class CVReview extends Model
{
    use HasFactory;
    protected $table = 'cv_reviews';
    public $timestamps = false;
    protected $fillable = [
        'service_id',
        'file_format',
        'file_path',
        'file_size',
        'review_status',
        'submission_date'
    ];
    public function service()  
{  
    return $this->belongsTo(Service::class, 'service_id');  
}

}
