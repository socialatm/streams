<?php

namespace Code\Lib;

use Code\Daemon\Run;

class CommentApproval
{
    protected $channel;
    protected $item;

    public function __construct($channel, $item)
    {
        $this->channel = $channel;
        $this->item = $item;
    }

    public function Accept()
    {
        $obj = $this->item['obj'];
        if (! is_array($obj)) {
            $obj = json_decode($obj, true);
        }
        $parent = $this->get_parent();
        $activity = post_activity_item(
            [
                'verb' => 'Accept',
                'obj_type' => $obj['type'],
                'obj' => $obj['id'],
                'item_wall' => 1,
                'allow_cid' => '',
                'owner_xchan' => $this->channel['xchan_hash'],
                'author_xchan' => $this->channel['xchan_hash'],
                'parent_mid' => $parent,
                'thr_parent' => $parent,
                'uid' => $this->channel['channel_id'],
                'title' => 'comment accepted'
            ],
            deliver: false,
            channel: $this->channel,
            observer: $this->channel
        );
        if ($activity['item_id']) {
            IConfig::Set($activity['item_id'], 'system', 'comment_recipient', $this->item['author_xchan']);
        }
        Run::Summon(['Notifier', 'comment_approval', $activity['item_id']]);
    }

    public function Reject()
    {
        $obj = $this->item['obj'];
        if (! is_array($obj)) {
            $obj = json_decode($obj, true);
        }
        $parent = $this->get_parent();
        $activity = post_activity_item(
            [
                'verb' => 'Reject',
                'obj_type' => $obj['type'],
                'obj' => $obj['id'],
                'item_wall' => 1,
                'allow_cid' => '',
                'owner_xchan' => $this->channel['xchan_hash'],
                'author_xchan' => $this->channel['xchan_hash'],
                'parent_mid' => $parent,
                'thr_parent' => $parent,
                'uid' => $this->channel['channel_id'],
                'title' => 'comment rejected'
            ],
            deliver: false,
            channel: $this->channel,
            observer: $this->channel
        );
        if ($activity['item_id']) {
            IConfig::Set($activity['item_id'], 'system', 'comment_recipient', $this->item['author_xchan']);
        }
        Run::Summon(['Notifier', 'comment_approval', $activity['item_id']]);
    }

    /**
     * To be considered valid, the Accept activity referenced in replyApproval MUST
     * satisfy the following properties:
     *
     * its actor property is the authority
     * its authenticity can be asserted
     * its object property is the reply under consideration
     * its inReplyTo property matches that of the reply under consideration
     *
     * In addition, if the reply is considered valid, but has no valid replyApproval
     * despite the object it is in reply to having a canReply property, the recipient MAY hide
     * the reply from certain views.
     */
    public static function verify($item, $channel, $approvalActivity = null)
    {

        if(!$approvalActivity) {
            $approvalActivity = Activity::fetch($item['approved'], $channel, true);
        }
        if (! $approvalActivity) {
            logger('no approval activity');
            return false;
        }
        $parent_item = q("select * from item where mid = '%s'", $item['parent_mid']);
        if (!$parent_item) {
            logger('no parent item');
            return false;
        }

        if ($approvalActivity instanceof ActivityStreams) {
            $act = $approvalActivity;
        }
        else {
            $act = new ActivityStreams($approvalActivity);
        }
        if (! $act->is_valid()) {
            logger('invalid parse');
            return false;
        }
        if (!$act->obj) {
            logger('no object');
            return false;
        }
        if ($act->type !== 'Accept') {
            logger('not an accept');
            return false;
        }
        if (!isset($act->actor)) {
            logger('no actor');
            return false;
        }
        $sender = Activity::find_best_identity($act->actor['id']);
        if (!$sender || $sender !== $parent_item[0]['owner_xchan']) {
            logger('no identity');
            return false;
        }
        $comment = is_string($act->obj) ? $act->obj : $act->obj['id'];
        if ($comment !== $item['mid']) {
            logger('incorrect mid');
            return false;
        }
        if(!in_array($act->parent_id, [$item['thr_parent'], $item['parent_mid']])) {
            logger('wrong provenance');
            logger('act: ' . $act->parent_id);
            logger('item: ' . print_r($item,true));
            return false;
        }
        logger('comment verified', LOGGER_DEBUG);
        return true;
    }

    public static function verifyReject($item, $channel, $approvalActivity = null)
    {

        if(!$approvalActivity) {
            $approvalActivity = Activity::fetch($item['approved'], $channel, true);
        }
        $parent_item = q("select * from item where mid = '%s'", $item['parent_mid']);

        if (! $approvalActivity) {
            return false;
        }
        if (!$parent_item) {
            return false;
        }

        if ($approvalActivity instanceof ActivityStreams) {
            $act = $approvalActivity;
        }
        else {
            $act = new ActivityStreams($approvalActivity);
        }
        if (! $act->is_valid()) {
            return false;
        }
        if (!$act->obj) {
            return false;
        }
        if ($act->type !== 'Reject') {
            return false;
        }
        if (!isset($act->actor)) {
            return false;
        }
        $sender = Activity::find_best_identity($act->actor['id']);
        if (!$sender || $sender !== $parent_item['owner_xchan']) {
            return false;
        }
        $comment = is_string($act->obj) ? $act->obj : $act->obj['id'];
        if ($comment !== $item['mid']) {
            return false;
        }
        if(!in_array($act->parent_id, [$item['thr_parent'], $item['parent_mid']])) {
            return false;
        }
        logger('comment verified', LOGGER_DEBUG);
        return true;
    }

    public static function doVerify($arr, $channel, $act)
    {
        logger('verifying comment accept/reject', LOGGER_DEBUG);
        $i = q("select * from item where mid = '%s' and uid = %d",
            dbesc(is_array($arr['obj']) ? $arr['obj']['id'] : $arr['obj']),
            intval($channel['channel_id'])
        );
        if ($i) {

            if ($arr['verb'] === 'Accept' && !$i[0]['approved']) {
                $valid = self::verify($i[0], $channel, $act);
                if ($valid) {
                    self::storeApprove($arr, $channel, $arr['mid']);
                    Run::Summon(['Notifier', 'activity', $i[0]['id']]);
                }
            }
            elseif ($i[0]['approved']) {
                $valid = self::verifyReject($i[0], $channel, $act);
                if ($valid) {
                    self::storeApprove($arr, $channel, '');
                    Run::Summon(['Notifier', 'activity', $i[0]['id']]);
                }
            }
            return true;
        }
        return false;
    }

    public static function storeApprove($arr, $channel, $value)
    {
        q("update item set approved = '%s' where mid = '%s' and uid = %d",
            dbesc($value),
            dbesc(is_array($arr['obj']) ? $arr['obj']['id'] : $arr['obj']),
            intval($channel['channel_id'])
        );
        $saved = q("select * from item where mid = '%s' and uid = %d",
            dbesc(is_array($arr['obj']) ? $arr['obj']['id'] : $arr['obj']),
            intval($channel['channel_id'])
        );
        if ($saved) {
            // we will need to remove the object and provide an author array
            // in order to re-generate the object JSON with the added approval
            xchan_query($saved);
            $saved[0]['obj'] = '';
            q("update item set obj = '%s' where mid = '%s' and uid = '%s'",
                dbesc(json_encode(Activity::encode_item($saved[0], true), JSON_UNESCAPED_SLASHES)),
                dbesc(is_array($arr['obj']) ? $arr['obj']['id'] : $arr['obj']),
                intval($channel['channel_id'])
            );
        }
    }


    protected function get_parent()
    {
        $result = q("select parent_mid from item where mid = '%s'",
            dbesc($this->item['parent_mid'])
        );
        return $result ? $result[0]['parent_mid'] : '';
    }

}
