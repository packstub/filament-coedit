<?php

namespace Packstub\CoEdit\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Packstub\CoEdit\CoEdit;

/**
 * The tab is closing: the browser sends a beacon here so the person leaves
 * the record at once instead of after the timeout. The URL is signed for one
 * room and one tab, so it can only ever remove the tab that asked.
 */
class LeaveController
{
    public function __invoke(Request $request): Response
    {
        CoEdit::store()->leave((string) $request->query('room'), (string) $request->query('tab'));

        return response()->noContent();
    }
}
