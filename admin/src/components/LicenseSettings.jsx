import { useEffect, useState } from 'react';
import { IconKey, IconCheck, IconAlertTriangle, IconExternalLink } from '@tabler/icons-react';

// Per-status presentation rules. `tone` drives the row color band.
const STATUS_PRESENTATION = {
    valid:           { tone: 'ok',    icon: 'check',  label: 'Active' },
    expired:         { tone: 'warn',  icon: 'warn',   label: 'Expired' },
    invalid:         { tone: 'error', icon: 'warn',   label: 'Invalid key' },
    missing:         { tone: 'error', icon: 'warn',   label: 'Key not found at store' },
    site_inactive:   { tone: 'warn',  icon: 'warn',   label: 'Site not activated' },
    item_name_mismatch: { tone: 'error', icon: 'warn', label: 'Key issued for a different product' },
    transport_error: { tone: 'warn',  icon: 'warn',   label: 'Couldn\'t reach the license store' },
    not_set:         { tone: 'warn',  icon: 'warn',   label: 'No key configured' },
    unknown:         { tone: 'warn',  icon: 'warn',   label: 'Unknown' },
    unavailable:     { tone: 'error', icon: 'warn',   label: 'License manager unavailable' },
};

export default function LicenseSettings({ bootstrap }) {
    const [loading, setLoading] = useState(true);
    const [state, setState]     = useState({ configured: false, status: 'not_set' });
    const [keyInput, setKeyInput] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [error, setError]     = useState('');
    const [success, setSuccess] = useState('');

    const restUrl = bootstrap?.restUrl || '';
    const nonce   = bootstrap?.nonce || '';

    useEffect(() => {
        (async () => {
            try {
                const res = await fetch(`${restUrl}license`, { headers: { 'X-WP-Nonce': nonce } });
                const data = await res.json();
                setState(data);
            } catch {
                setError('Could not load license state.');
            } finally {
                setLoading(false);
            }
        })();
    }, [restUrl, nonce]);

    const activate = async () => {
        const key = keyInput.trim();
        if (key === '') {
            setError('Enter a license key.');
            return;
        }
        setSubmitting(true);
        setError(''); setSuccess('');
        try {
            const res = await fetch(`${restUrl}license/activate`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': nonce,
                },
                body: JSON.stringify({ key }),
            });
            const data = await res.json();
            setState(data.state || state);
            if (res.ok && data.ok) {
                setKeyInput('');
                setSuccess('License activated.');
            } else {
                const present = STATUS_PRESENTATION[data.status] || STATUS_PRESENTATION.unknown;
                setError(data.error || `Activation failed — ${present.label}.`);
            }
        } catch (e) {
            setError(e?.message || 'Activation failed.');
        } finally {
            setSubmitting(false);
        }
    };

    const deactivate = async () => {
        setSubmitting(true);
        setError(''); setSuccess('');
        try {
            const res = await fetch(`${restUrl}license/deactivate`, {
                method: 'POST',
                headers: { 'X-WP-Nonce': nonce },
            });
            const data = await res.json();
            setState(data.state || state);
            setSuccess('License cleared.');
        } catch (e) {
            setError(e?.message || 'Deactivation failed.');
        } finally {
            setSubmitting(false);
        }
    };

    if (loading) {
        return (
            <div className="hof-ai-settings">
                <h2 className="hof-ai-settings-title">License</h2>
                <p className="hof-ai-settings-status">Loading…</p>
            </div>
        );
    }

    const present = STATUS_PRESENTATION[state.status] || STATUS_PRESENTATION.unknown;
    const StatusIcon = present.icon === 'check' ? IconCheck : IconAlertTriangle;

    return (
        <div className="hof-ai-settings" id="license">
            <header className="hof-ai-settings-header">
                <span className="hof-ai-settings-icon"><IconKey size={20} stroke={1.75} /></span>
                <div>
                    <h2 className="hof-ai-settings-title">License</h2>
                    <p className="hof-ai-settings-sub">
                        Activate this site against your Hooked on Facets license. Activation
                        enables automatic plugin updates and unlocks any features that ship
                        gated to licensed installs.
                    </p>
                </div>
            </header>

            <section className="hof-ai-settings-section">
                <h3 className="hof-ai-settings-section-title">Current status</h3>

                <div className={`hof-ai-settings-row hof-ai-settings-row--${present.tone}`}>
                    <span className="hof-ai-settings-row-icon">
                        <StatusIcon size={16} stroke={2} />
                    </span>
                    <div>
                        <p className="hof-ai-settings-row-line">
                            <strong>{present.label}</strong>
                            {state.configured && state.fingerprint && (
                                <>{' '}— key <code>{state.fingerprint}</code></>
                            )}
                        </p>
                        {state.configured && (
                            <p className="hof-ai-settings-row-sub">
                                {state.expires_human && <>Renewal: {state.expires_human} · </>}
                                {state.site_count != null && state.license_limit != null && (
                                    <>{state.site_count} of {state.license_limit === 0 ? '∞' : state.license_limit} sites in use · </>
                                )}
                                Enforcement: <code>{state.enforcement}</code>
                                {state.store_url && (
                                    <> · Store: <a href={state.store_url} target="_blank" rel="noopener">{state.store_url}</a></>
                                )}
                            </p>
                        )}
                    </div>
                </div>

                <div className="hof-ai-settings-input-row">
                    <label className="hof-ai-settings-label" htmlFor="hof-license-key">
                        {state.configured ? 'Replace with a different key' : 'Paste your license key'}
                    </label>
                    <div className="hof-ai-settings-input-wrap">
                        <span className="hof-ai-settings-input-icon"><IconKey size={16} stroke={1.75} /></span>
                        <input
                            id="hof-license-key"
                            type="text"
                            autoComplete="off"
                            spellCheck="false"
                            className="hof-ai-settings-input"
                            placeholder="hof_xxxxxxxxxxxxxxxxxxxxxxxx"
                            value={keyInput}
                            onChange={(e) => setKeyInput(e.target.value)}
                            disabled={submitting}
                        />
                    </div>
                    <div className="hof-ai-settings-actions">
                        <button
                            type="button"
                            className="hof-ai-settings-btn hof-ai-settings-btn--primary"
                            disabled={submitting || keyInput.trim() === ''}
                            onClick={activate}
                        >
                            {submitting ? 'Working…' : 'Activate'}
                        </button>
                        {state.configured && (
                            <button
                                type="button"
                                className="hof-ai-settings-btn hof-ai-settings-btn--ghost"
                                disabled={submitting}
                                onClick={deactivate}
                            >
                                Deactivate
                            </button>
                        )}
                    </div>
                    {error   && <p className="hof-ai-settings-msg hof-ai-settings-msg--error">{error}</p>}
                    {success && <p className="hof-ai-settings-msg hof-ai-settings-msg--ok">{success}</p>}
                </div>
            </section>

            <section className="hof-ai-settings-section">
                <h3 className="hof-ai-settings-section-title">How licensing works</h3>
                <ul className="hof-ai-settings-list">
                    <li>One license = one or more sites, depending on your plan. The store reports the seat limit when you activate.</li>
                    <li>An active license enables auto-updates via WordPress's standard plugin updater — new releases download with the key embedded in the URL.</li>
                    <li>During the pre-alpha period, enforcement is <strong>soft</strong>: the plugin works without a license. Future releases may gate features to active licenses.</li>
                    <li>
                        Need a key? Visit{' '}
                        <a href={state.store_url || 'https://hookedonfacets.com'} target="_blank" rel="noopener">
                            {state.store_url || 'hookedonfacets.com'}{' '}<IconExternalLink size={12} stroke={1.75} />
                        </a>.
                    </li>
                </ul>
            </section>
        </div>
    );
}
