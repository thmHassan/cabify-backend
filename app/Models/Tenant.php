<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;
use Illuminate\Notifications\Notifiable;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;
use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\DatabaseConfig;

class Tenant extends BaseTenant implements JWTSubject, TenantWithDatabase
{
    use HasFactory, Notifiable;

    protected $guarded = ['id'];

    protected $fillable = [
        'company_name',
        'email',
        'password',
        'company_admin_name',
        'contact_person',
        'phone',
        'address',
        'city',
        'currency',
        'maps_api',
        'google_api_key',
        'barikoi_api_key',
        'search_api',
        'log_map_search_result',
        'voip',
        'drivers_allowed',
        'sub_company',
        'passengers_allowed',
        'uber_plot_hybrid',
        'dispatchers_allowed',
        'subscription_type',
        'fleet_management',
        'sos_features',
        'notes',
        'stripe_enable',
        'stripe_enablement',
        'units',
        'country_of_use',
        'time_zone',
        'enable_smtp',
        'dispatcher',
        'map',
        'push_notification',
        'usage_monitoring',
        'revenue_statements',
        'zone',
        'manage_zones',
        'cms',
        'lost_found',
        'accounts',
        'status',
        'picture',
        'database'
    ];


    protected $casts = [
        'data' => 'array', 
    ];

    protected $primaryKey = 'id'; 
    public $incrementing = false; 
    protected $keyType = 'string'; 

    public function database(): DatabaseConfig
    {
        return new DatabaseConfig($this, [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => 'tenant_' . $this->id, 
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
        ]);
    }

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return ['role' => $this->role];
    }

    public function setTenantData(array $info)
    {
        $this->data = $info;
    }

    public function getSubscriptionIdAttribute()
    {
        return $this->data['subscription_type'] && is_int($this->data['subscription_type']) ?? null;
    }

    public function subscription()
    {
        return $this->hasOne(Subscription::class, "id", 'subscription_id');
    }

}
