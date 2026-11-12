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

function XboardNodeTraffic({ user }) {
  const h = H.createElement;
  const { t } = jy('user');
  const mobile = p8e();
  const [open, setOpen] = H.useState(false);
  const focusTitle = H.useCallback((element) => element?.focus(), []);
  const { data, isFetching, isError, refetch } = pC({
    queryKey: ['userNodeTraffic', user.id],
    queryFn: async () => {
      const response = await IL(OL() + '/user/getNodeTraffic', { params: { id: user.id } });
      return response.data;
    },
    enabled: open,
    staleTime: 0,
  });
  const percent = user.transfer_enable > 0 ? (user.total_used / user.transfer_enable) * 100 : 0;
  let content;
  // Hide old cached totals while reopening, especially after a traffic reset.
  if (isFetching) content = h('p', { className: 'py-8 text-center text-muted-foreground', role: 'status' }, t('nodeTraffic.loading'));
  else if (isError) content = h('div', { className: 'space-y-4 py-8 text-center', role: 'alert' },
    h('p', null, t('nodeTraffic.loadError')),
    h(xtt, { variant: 'outline', onClick: () => refetch() }, t('nodeTraffic.retry')));
  else if (data) {
    const summary = data.summary;
    content = h('div', { className: 'space-y-4' },
      h('div', { className: 'flex flex-wrap gap-4 text-sm' },
        h('span', null, t('nodeTraffic.used', { value: RS(summary.total_used) })),
        h('span', null, t('nodeTraffic.attributed', { value: RS(summary.attributed_total) }))),
      summary.recorded_from ? h('p', { className: 'text-xs text-muted-foreground' },
        t('nodeTraffic.recordedFrom', { date: SS(summary.recorded_from) })) : null,
      summary.unattributed_total > 0 ? h('p', { className: 'rounded-md border p-3 text-sm', role: 'note' },
        t('nodeTraffic.unattributed', { value: RS(summary.unattributed_total) })) : null,
      summary.inconsistent ? h('p', { className: 'text-sm text-destructive', role: 'alert' }, t('nodeTraffic.inconsistent')) : null,
      !data.list.length ? h('p', { className: 'py-8 text-center text-muted-foreground' }, t('nodeTraffic.empty'))
        : h('div', { style: { maxHeight: 'min(60vh, 480px)', overflow: 'auto' } },
          h('table', { className: 'w-full text-sm' },
            h('thead', null, h('tr', { className: 'border-b text-left text-muted-foreground' },
              ...['node', 'upload', 'download'].map((key) => h('th', { key, className: 'whitespace-nowrap p-3' }, t('nodeTraffic.' + key))))),
            h('tbody', null, ...data.list.map((record) => h('tr', { key: record.server_id, className: 'border-b' },
              h('td', { className: 'p-3', style: { minWidth: '160px', overflowWrap: 'anywhere' } },
                record.server_name, h('span', { className: 'ml-2 text-xs text-muted-foreground' },
                  '#' + record.server_id + (record.deleted ? ' · ' + t('nodeTraffic.deleted') : ''))),
              h('td', { className: 'whitespace-nowrap p-3' }, RS(record.upload)),
              h('td', { className: 'whitespace-nowrap p-3' }, RS(record.download))
            )))))
    );
  }

  return h(htt, { open, onOpenChange: setOpen },
    h(gtt, { asChild: true }, h('button', {
      type: 'button',
      className: 'w-full space-y-1 rounded text-left hover:text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
      title: t('nodeTraffic.title'),
      'aria-label': t('nodeTraffic.view', { email: user.email }),
      onClick: (event) => {
        event.stopPropagation();
        event.currentTarget.blur();
      },
    },
    h('span', { className: 'flex justify-between gap-2 text-sm' },
      h('span', { className: 'underline decoration-dotted underline-offset-4' }, RS(user.total_used)),
      h('span', { className: 'text-xs text-muted-foreground' }, percent.toFixed(1) + '%')),
    h('span', { className: 'block h-1.5 w-full rounded-full bg-secondary' },
      h('span', { className: Im('block h-full rounded-full transition-all', percent > 90 ? 'bg-destructive' : 'bg-primary'),
        style: { width: Math.max(0, Math.min(percent, 100)) + '%' } })))),
    h(ptt, { className: 'max-w-2xl', style: { maxHeight: '90vh', overflowY: 'auto' } },
      h(mtt, null,
        h(_tt, { ref: focusTitle, tabIndex: -1, className: 'outline-none' }, t('nodeTraffic.title')),
        h(vtt, { className: 'break-all text-sm text-muted-foreground' }, user.email + ' · ' + t('nodeTraffic.description'))),
      h('div', { className: 'space-y-4', style: { minWidth: 0, padding: mobile ? '0 16px 16px' : undefined } },
        content,
        h('div', { className: 'flex justify-end' },
          h(xtt, { variant: 'outline', onClick: () => setOpen(false) }, t('common:close'))))
    )
  );
}

// Snell node settings. `version` is the server version (5 or 6); the node PSK
// is shared by all users, who authenticate with their UUID as the user key.
function XboardSnellSchema(routing) {
  return py({
    version: yy().default(5),
    psk: cy().default(''),
    obfs_mode: cy().default('none'),
    mode: cy().default('default'),
    routing: routing.optional().default({}),
  });
}

function XboardSnellForm({ form, t }) {
  const h = H.createElement;
  const label = (key) => h(Zy, { className: 'text-[12px] font-mono text-foreground/80' }, t(key));
  const select = (name, options, fallback, parse = (value) => value) => h($y, {
    key: name,
    control: form.control,
    name,
    render: ({ field }) => h(Gy, { className: 'flex-1' },
      label('dynamic_form.snell.' + name + '.label'),
      h(Yy, null,
        h(yzt, { value: String(field.value || fallback), onValueChange: (value) => field.onChange(parse(value)) },
          h(Czt, { className: 'font-mono text-xs' }, h(wzt, null)),
          h(Nzt, null, h(xzt, null, options.map((option) =>
            h(Lzt, { key: option, value: option, className: 'cursor-pointer' }, option))))))),
  });
  const generate = () => {
    const bytes = crypto.getRandomValues(new Uint8Array(24));
    form.setValue('psk', window.btoa(String.fromCharCode(...bytes)), { shouldDirty: true });
  };

  return h(H.Fragment, null,
    h('div', { className: 'flex gap-2' },
      select('version', ['5', '6'], 5, Number),
      form.watch('version') == 6
        ? select('mode', ['default', 'unshaped', 'unsafe-raw'], 'default')
        : select('obfs_mode', ['none', 'http'], 'none')),
    h($y, {
      control: form.control,
      name: 'psk',
      render: ({ field }) => h(Gy, null,
        label('dynamic_form.snell.psk.label'),
        h(Yy, null, h('div', { className: 'flex gap-2' },
          h(u8e, { className: 'font-mono text-xs', placeholder: t('dynamic_form.snell.psk.placeholder'), ...field }),
          h(xtt, {
            type: 'button', variant: 'outline', size: 'icon', className: 'h-9 w-9 shrink-0',
            title: t('dynamic_form.snell.psk.generate'), 'aria-label': t('dynamic_form.snell.psk.generate'),
            onClick: generate,
          }, h(YXt, { icon: 'lucide:rotate-cw', className: 'h-4 w-4' }))))),
    }));
}
