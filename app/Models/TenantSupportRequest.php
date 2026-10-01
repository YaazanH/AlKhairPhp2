<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class TenantSupportRequest extends BaseModel { public const TYPE_PROBLEM='problem';public const TYPE_SUGGESTION='suggestion';protected $fillable=['type','priority','status','subject','message','reported_url','browser_info','submitted_by_user_id','forwarded_by_user_id','forwarded_at','tenant_admin_note'];protected function casts():array{return ['forwarded_at'=>'datetime'];}public function submittedBy():BelongsTo{return $this->belongsTo(User::class,'submitted_by_user_id');}public function forwardedBy():BelongsTo{return $this->belongsTo(User::class,'forwarded_by_user_id');}}
