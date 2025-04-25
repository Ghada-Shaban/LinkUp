namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class Admin extends Authenticatable
{
    use HasApiTokens, HasFactory;

    protected $table = 'admins';
    protected $primaryKey = "id";
    public $incrementing = true;
    protected $keyType = 'int';

    protected $fillable = [
        'Full_Name',
        'Email',
        'Password'
    ];

    protected $hidden = [
        'password',
        'remember_token'
    ];

    public function coaches()
    {
        return $this->hasMany(Coach::class, 'id');
    }
}
