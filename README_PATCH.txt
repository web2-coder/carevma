CareVMA - VA Shift End / Checkout Offline / One-time Work Summary Patch

Behavior:
1. Active VA dashboard shows Doctor shift end, VA-local shift end, and live remaining time.
2. After Doctor shift end, dashboard says extra work is overtime; it does NOT force checkout at shift end.
3. On actual checkout, attendance is finalized and Live Workforce treats the VA as Offline immediately.
4. Checkout response includes a one-time Today's Work Summary modal:
   Clock In, Clock Out, Break, Regular Work, Overtime + status, Approved OT, Payable Time.
5. Existing Attendance page remains the permanent history after the one-time summary is dismissed.

Important: No migration required.
Build frontend locally before deployment.
