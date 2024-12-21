<?php

namespace App\Http\Services;

use App\Models\Shift;
use App\Models\History;

class HistoryService
{
    /**
     * 履歴を作成
     *
     * @param int $user_id
     * @param int $shift_id
     * @return void
     */
    public function create_history(int $user_id, int $shift_id): void
    {
        // History::CURRENTの履歴をUNDO_TARGETに変更
        $this->update_history_undo_target($user_id, $shift_id);

        // 新たに履歴を作成
        $history = History::create([
            'user_id' => $user_id,
            'status' => History::CURRENT,
        ]);

        // 現状適用されているシフトを取得
        $shift = Shift::find($shift_id);

        // 履歴にシフトを保存
        $shift->shift_staffs()->each(function ($shift_staff) use ($history) {
            $history->history_shift_staffs()->create([
                'shift_id' => $shift_staff->shift_id,
                'staff_id' => $shift_staff->staff_id,
                'start_time' => $shift_staff->start_time,
                'end_time' => $shift_staff->end_time,
            ]);
        });

        // redo対象を削除
        $this->clear_redo_target($user_id, $shift_id);
    }

    /**
     * @param int $user_id
     * @param int $shift_id
     * @return void
     */
    private function update_history_undo_target(int $user_id, int $shift_id): void
    {
        $histories = History::where([
            'user_id' => $user_id,
            'status' => History::CURRENT,
        ]);

        if ($histories->exists()) {
            $histories->update([
                'status' => History::UNDO_TARGET,
            ]);
        }
    }

    /**
     * @param int $user_id
     * @param int $shift_id
     * @return void
     */
    private function clear_redo_target(int $user_id, int $shift_id): void
    {
        $history = History::where([
            'user_id' => $user_id,
            'status' => History::REDO_TARGET,
        ]);

        if ($history->exists()) {
            $history->delete();
        }
    }

    /**
     * 履歴からシフトにコピー ※Undo/Redo処理
     *
     * @param int $user_id
     * @param Shift $shift
     * @return Shift
     */
    public function copy_history_to_target(int $user_id, Shift $shift, int $redo_flg): Shift
    {
        // シフトの子テーブルを一旦初期化
        $this->clear($shift);

        // 初期化後のシフトを取得
        $target = Shift::find($shift->id);

        // 現在適用されている履歴を取得
        $current_history = $this->fetch_current_history($user_id);

        if ($redo_flg) {
            // 直近のredo対象を取得
            $history = $this->fetch_earliest_history($user_id);
        } else {
            // 直近のundo対象を取得
            $history = $this->fetch_latest_history($user_id);
        }

        // 履歴をシフトにコピー
        $history_shift_staffs = $history->history_shift_staffs();
        if ($history_shift_staffs->exists()) {
            foreach ($history_shift_staffs->get() as $history_shift_staff) {
                $target->shift_staffs()->create([
                    'shift_id' => $history_shift_staff->shift_id,
                    'staff_id' => $history_shift_staff->staff_id,
                    'start_time' => $history_shift_staff->start_time,
                    'end_time' => $history_shift_staff->end_time,
                ]);
            }
        }

        // 使用したhistoryをCURRENTに変更
        $this->set_current_history($history->id);
        if ($redo_flg) {
            // 適用されていた履歴をundo対象に変更
            $this->set_undo_target($current_history->id);
        } else {
            // 適用されていた履歴をredo対象に変更
            $this->set_redo_target($current_history->id);
        }

        // コピーしたシフトを返却
        return Shift::with([
            'shift_staffs',
        ])->find($target->id);
    }

    /**
     * @param Shift $shift
     * @return void
     */
    private function clear(Shift $shift): void
    {
        $shift->shift_staffs()->each(function ($shift_staff) {
            $shift_staff->delete();
        });
    }

    /**
     * @param int $user_id
     * @return History
     */
    private function fetch_current_history(int $user_id): History
    {
        return $this->fetch_history($user_id, History::CURRENT, 'desc');
    }

    /**
     * @param int $user_id
     * @return History
     */
    private function fetch_latest_history(int $user_id): History
    {
        return $this->fetch_history($user_id, History::UNDO_TARGET, 'desc');
    }

    /**
     * @param int $user_id
     * @return History
     */
    private function fetch_earliest_history(int $user_id): History
    {
        return $this->fetch_history($user_id, History::REDO_TARGET, 'asc');
    }

    /**
     * @param int $user_id
     * @param int $status
     * @param string $sort
     * @return History
     */
    private function fetch_history(int $user_id, int $status, string $sort): History
    {
        return History::where([
            'user_id' => $user_id,
            'status' => $status,
        ])->with([
            'history_shift_staffs',
        ])->orderBy('id', $sort)->first();
    }

    /**
     * @param int $history_id
     * @return void
     */
    private function set_current_history(int $history_id): void
    {
        $this->update_history($history_id, History::CURRENT);
    }

    /**
     * @param int $history_id
     * @return void
     */
    private function set_redo_target(int $history_id): void
    {
        $this->update_history($history_id, History::REDO_TARGET);
    }

    /**
     * @param int $history_id
     * @return void
     */
    private function set_undo_target(int $history_id): void
    {
        $this->update_history($history_id, History::UNDO_TARGET);
    }

    /**
     * @param int $history_id
     * @param int $status
     * @return void
     */
    private function update_history(int $history_id, int $status): void
    {
        History::where([
            'id' => $history_id,
        ])->update([
            'status' => $status,
        ]);
    }

    /**
     * undo対象の履歴があるかを確認する ※Undoボタンの制御用
     *
     * @param int $user_id
     * @return bool
     */
    public function exist_undo_target(int $user_id): bool
    {
        return $this->exist_undo_redo_target($user_id, History::UNDO_TARGET);
    }

    /**
     * redo対象の履歴があるかを確認する ※Redoボタンの制御用
     *
     * @param int $user_id
     * @return bool
     */
    public function exist_redo_target(int $user_id): bool
    {
        return $this->exist_undo_redo_target($user_id, History::REDO_TARGET);
    }

    /**
     * @param int $user_id
     * @param int $status
     * @return bool
     */
    private function exist_undo_redo_target(int $user_id, int $status): bool
    {
        return History::where([
            'user_id' => $user_id,
            'status' => $status,
        ])->exists();
    }
}
