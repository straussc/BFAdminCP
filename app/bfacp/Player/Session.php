<?php namespace BFACP\Player;

use Illuminate\Database\Eloquent\Model AS Eloquent;
use Carbon\Carbon;

class Session extends Eloquent
{
    /**
     * Table name
     * @var string
     */
    protected $table = 'tbl_sessions';

    /**
     * Table primary key
     * @var string
     */
    protected $primaryKey = 'StatsID';

    /**
     * Fields not allowed to be mass assigned
     * @var array
     */
    protected $guarded = ['*'];

    /**
     * Date fields to convert to carbon instances
     * @var array
     */
    protected $dates = ['StartTime', 'EndTime'];

    /**
     * Should model handle timestamps
     *
     * @var boolean
     */
    public $timestamps = FALSE;

    /**
     * Append custom attributes to output
     * @var array
     */
    protected $appends = [];

    /**
     * Models to be loaded automaticly
     * @var array
     */
    protected $with = [];

    /**
     * @return \Illuminate\Database\Eloquent\Model
     */
    public function server()
    {
        return $this->belongsToMany('BFACP\Battlefield\Server', 'tbl_server_player');
    }
}