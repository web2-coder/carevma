<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ChatMessage extends Model {
    protected $fillable = ['conversation_id','sender_id','body'];
    public function sender(){ return $this->belongsTo(User::class, 'sender_id'); }
}
