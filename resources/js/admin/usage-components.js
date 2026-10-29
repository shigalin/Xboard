// The admin source is not distributed with XBoard. These components reuse the
// React, query, chart and dialog bindings in the pinned a260c84d admin build.
// scripts/customize-admin.php checks the upstream bundle before inserting them.

function XboardUsageToday() {
  // Application time is UTC+8. Read its calendar fields without a browser-DST
  // offset round trip, then represent that day in the local calendar picker.
  const date = new Date(Date.now() + 8 * 60 * 60 * 1000);
  return new Date(date.getUTCFullYear(), date.getUTCMonth(), date.getUTCDate());
}

function XboardUsageSummary({ summary = {} }) {
  const h = H.createElement;
  const { t } = jy('dashboard');
  const metric = (title, value, detail, average) => h('div', { className: 'space-y-1' },
    h('div', { className: 'text-sm text-muted-foreground' }, title),
    h('div', { className: 'text-2xl font-bold' }, value),
    h('div', { className: 'text-xs text-muted-foreground' }, detail),
    h('div', { className: 'text-xs text-muted-foreground' }, average)
  );
  return h('div', { className: 'grid grid-cols-2 gap-4' },
    metric(t('usage.users'), Number(summary.active_users || 0).toLocaleString(),
      t('usage.uniqueUsers'), t('usage.avgUsers', { count: summary.avg_daily_users || 0 })),
    metric(t('usage.traffic'), RS(summary.traffic || 0),
      t('usage.trafficBasis'), t('usage.avgTraffic', { value: RS(summary.avg_daily_traffic || 0) }))
  );
}

function XboardUsageTooltip({ active, payload, label }) {
  const h = H.createElement;
  const { t } = jy('dashboard');
  if (!active || !payload?.length) return null;
  return h('div', { className: 'rounded-lg border bg-background p-3 shadow-sm' },
    h('div', { className: 'mb-2 text-sm font-medium' }, label),
    ...payload.map((entry) => h('div', { key: entry.dataKey, className: 'flex items-center gap-2 text-sm' },
      h('span', { className: 'h-2 w-2 rounded-full', style: { backgroundColor: entry.color } }),
      h('span', { className: 'text-muted-foreground' }, entry.name + ': '),
      h('span', { className: 'font-medium' }, entry.dataKey === 'traffic'
        ? RS(entry.value) : t('usage.people', { count: entry.value }))
    ))
  );
}

function XboardUsageContent({ data, metric, loading, error, retry }) {
  const h = H.createElement;
  const { t } = jy('dashboard');
  if (error) return h('div', { className: 'flex h-[400px] items-center justify-center gap-2', role: 'alert' },
    t('usage.loadError'), h(xtt, { variant: 'outline', onClick: () => retry() }, t('usage.retry')));
  if (loading) return h('div', { className: 'flex h-[400px] items-center justify-center', role: 'status' }, t('usage.loading'));

  const users = metric === 'users';
  const key = users ? 'active_users' : 'traffic';
  const color = 'hsl(var(--primary))';
  return h(H.Fragment, null,
    h('p', { className: 'mb-4 text-xs text-muted-foreground' }, t('usage.retention', { date: data?.summary?.available_from })),
    h(XboardUsageSummary, { summary: data?.summary }),
    h('div', { className: 'h-[400px] w-full', 'aria-label': t(users ? 'usage.dailyUsers' : 'usage.dailyTraffic') },
      h(LSt, { width: '100%', height: '100%' },
        h(QWt, { data: data?.list || [], margin: { top: 20, right: 20, left: 0, bottom: 0 } },
          h('defs', null, h('linearGradient', { id: 'usageGradient', x1: '0', y1: '0', x2: '0', y2: '1' },
            h('stop', { offset: '0%', stopColor: color, stopOpacity: 0.2 }),
            h('stop', { offset: '100%', stopColor: color, stopOpacity: 0.02 }))),
          h(WVt, { dataKey: 'date', axisLine: false, tickLine: false,
            tick: { fill: 'hsl(var(--muted-foreground))', fontSize: 12 }, tickFormatter: (date) => date.slice(5) }),
          h(QVt, { axisLine: false, tickLine: false, allowDecimals: !users,
            tick: { fill: 'hsl(var(--muted-foreground))', fontSize: 12 },
            tickFormatter: (value) => users ? t('usage.people', { count: value }) : RS(value) }),
          h(NBt, { strokeDasharray: '3 3', vertical: false, stroke: 'hsl(var(--border))', opacity: 0.3 }),
          h(XCt, { content: h(XboardUsageTooltip) }),
          h(sVt, { key, type: 'monotone', dataKey: key, name: t(users ? 'usage.users' : 'usage.traffic'),
            stroke: color, fill: 'url(#usageGradient)', strokeWidth: 2 })
        )
      )
    )
  );
}

function XboardUserDevices({ user, t }) {
  const h = H.createElement;
  const limit = user.device_limit;
  const count = user.online_count || 0;
  return h(Zot, { delayDuration: 100 },
    h(Yot, null,
      h(Xot, { asChild: true }, h('div', { className: 'flex items-center gap-1.5' },
        h(XboardOnlineIPs, { user }, h(nKt, {
          variant: 'outline',
          className: Im('min-w-[4rem] justify-center', limit !== null && count >= limit
            ? 'border-destructive/50 bg-destructive/10 text-destructive'
            : 'border-primary/40 bg-primary/5 text-primary/90'),
        }, count + ' / ' + (limit === null ? '∞' : limit))))),
      h(Qot, { side: 'bottom' }, h('p', { className: 'text-sm' },
        limit === null ? t('columns.device_limit.unlimited') : t('columns.device_limit.limited', { count: limit })))
    )
  );
}

function XboardOnlineIPs({ user, children, className }) {
  const h = H.createElement;
  const { t } = jy('user');
  const [open, setOpen] = H.useState(false);
  const focusTitle = H.useCallback((element) => element?.focus(), []);
  const { data, isLoading, isError, refetch } = pC({
    queryKey: ['userOnlineIPs', user.id],
    queryFn: async () => {
      const response = await IL(OL() + '/user/getOnlineIPs', { params: { id: user.id } });
      return response.data;
    },
    // Fetched only when the dialog is opened; no background polling, so the
    // admin UI keeps its previous request pattern.
    enabled: open,
    staleTime: 0,
  });
  let content;
  if (isLoading) content = h('p', { className: 'py-8 text-center text-muted-foreground', role: 'status' }, t('onlineIPs.loading'));
  else if (isError) content = h('div', { className: 'space-y-4 py-8 text-center', role: 'alert' },
    h('p', null, t('onlineIPs.loadError')),
    h(xtt, { variant: 'outline', onClick: () => refetch() }, t('onlineIPs.retry')));
  else if (!data?.length) content = h('p', { className: 'py-8 text-center text-muted-foreground' }, t('onlineIPs.empty'));
  else content = h('div', { style: { maxHeight: 'min(60vh, 480px)', overflow: 'auto' } },
    h('table', { className: 'w-full text-sm' },
      h('thead', null, h('tr', { className: 'border-b text-left text-muted-foreground' },
        h('th', { className: 'p-3' }, 'IP'),
        h('th', { className: 'p-3' }, t('onlineIPs.lastSeen')),
        h('th', { className: 'p-3' }, t('onlineIPs.status')))),
      h('tbody', null, ...data.map((record) => h('tr', { key: record.ip, className: 'border-b' },
        h('td', { className: 'break-all p-3 font-mono' }, record.ip),
        h('td', { className: 'whitespace-nowrap p-3' }, SS(record.last_seen_at)),
        h('td', { className: 'whitespace-nowrap p-3' }, h(nKt, {
          variant: record.is_online ? 'default' : 'secondary',
        }, t(record.is_online ? 'onlineIPs.online' : 'onlineIPs.offline')))
      )))
    ));

  return h(htt, { open, onOpenChange: setOpen },
    h(gtt, { asChild: true }, h('button', {
      type: 'button',
      className: Im('inline-flex cursor-pointer items-center justify-center rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring', className),
      'aria-label': t('onlineIPs.view', { email: user.email }),
      onClick: (event) => {
        event.stopPropagation();
        event.currentTarget.blur();
      },
    }, children)),
    h(ptt, { className: 'max-w-2xl' },
      h(mtt, null,
        h(_tt, { ref: focusTitle, tabIndex: -1, className: 'outline-none' }, t('onlineIPs.title')),
        h(vtt, { className: 'break-all text-sm text-muted-foreground' },
          user.email + ' · ' + t('onlineIPs.description'))),
      content
    )
  );
}
