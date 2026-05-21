import { useEffect, useState } from 'react';
import { IconSparkle, IconKey, IconCheck, IconAlertTriangle } from '@tabler/icons-react';

// Bring-your-own-key admin panel. The key is sent once on save and never
// echoed back — only a fingerprint (first 14 / last 6 chars) is returned by
// GET /ai-settings so the admin can confirm which key is configured.

export default function AiSettings({ bootstrap }) {
    const [loading, setLoading] = useState(true);
    const [config, setConfig]   = useState({ configured: false, fingerprint: '', model: '' });
    const [keyInput, setKeyInput] = useState('');
    const [saving, setSaving]   = useState(false);
    const [error, setError]     = useState('');
    const [success, setSuccess] = useState('');

    const restUrl = bootstrap?.restUrl || '';
    const nonce   = bootstrap?.nonce || '';

    useEffect(() => {
        (async () => {
            try {
                const res = await fetch(`${restUrl}ai-settings`, {
                    headers: { 'X-WP-Nonce': nonce },
                });
                const data = await res.json();
                setConfig(data);
            } catch (e) {
                setError('Could not load AI settings.');
            } finally {
                setLoading(false);
            }
        })();
    }, [restUrl, nonce]);

    const save = async (newKey) => {
        setSaving(true);
        setError('');
        setSuccess('');
        try {
            const res = await fetch(`${restUrl}ai-settings`, {
                method:  'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce':   nonce,
                },
                body: JSON.stringify({ api_key: newKey }),
            });
            if (!res.ok) {
                const body = await res.json().catch(() => ({}));
                throw new Error(body?.error || `HTTP ${res.status}`);
            }
            const data = await res.json();
            setConfig(data);
            setKeyInput('');
            setSuccess(newKey === '' ? 'Key cleared.' : 'Key saved.');
        } catch (e) {
            setError(e.message || 'Save failed.');
        } finally {
            setSaving(false);
        }
    };

    if (loading) {
        return (
            <div className="hof-ai-settings">
                <h2 className="hof-ai-settings-title">Ask</h2>
                <p className="hof-ai-settings-status">Loading…</p>
            </div>
        );
    }

    return (
        <div className="hof-ai-settings">
            <header className="hof-ai-settings-header">
                <span className="hof-ai-settings-icon"><IconSparkle size={20} stroke={1.75} /></span>
                <div>
                    <h2 className="hof-ai-settings-title">Ask</h2>
                    <p className="hof-ai-settings-sub">
                        Powers the conversational <em>Ask</em> facet — turning natural-language requests
                        like <em>"red shoes under $50"</em> into editable filter chips via the Anthropic API.
                        Bring your own key — it's stored on this site and never sent to the browser or
                        shared with Hooked on Facets.
                    </p>
                </div>
            </header>

            <section className="hof-ai-settings-section">
                <h3 className="hof-ai-settings-section-title">API key</h3>
                {config.configured ? (
                    <div className="hof-ai-settings-row hof-ai-settings-row--ok">
                        <span className="hof-ai-settings-row-icon"><IconCheck size={16} stroke={2} /></span>
                        <div>
                            <p className="hof-ai-settings-row-line">
                                Configured. Key fingerprint: <code>{config.fingerprint}</code>
                            </p>
                            <p className="hof-ai-settings-row-sub">
                                Model: <code>{config.model}</code>
                            </p>
                        </div>
                    </div>
                ) : (
                    <div className="hof-ai-settings-row hof-ai-settings-row--warn">
                        <span className="hof-ai-settings-row-icon"><IconAlertTriangle size={16} stroke={2} /></span>
                        <p className="hof-ai-settings-row-line">
                            No key configured. The Ask endpoint will return <code>no_api_key</code> until you save one.
                        </p>
                    </div>
                )}

                <div className="hof-ai-settings-input-row">
                    <label className="hof-ai-settings-label" htmlFor="hof-ai-key">
                        {config.configured ? 'Replace with a new key' : 'Paste your Anthropic API key'}
                    </label>
                    <div className="hof-ai-settings-input-wrap">
                        <span className="hof-ai-settings-input-icon"><IconKey size={16} stroke={1.75} /></span>
                        <input
                            id="hof-ai-key"
                            type="password"
                            autoComplete="off"
                            spellCheck="false"
                            className="hof-ai-settings-input"
                            placeholder="sk-ant-api03-…"
                            value={keyInput}
                            onChange={(e) => setKeyInput(e.target.value)}
                            disabled={saving}
                        />
                    </div>
                    <div className="hof-ai-settings-actions">
                        <button
                            type="button"
                            className="hof-ai-settings-btn hof-ai-settings-btn--primary"
                            disabled={saving || keyInput.trim() === ''}
                            onClick={() => save(keyInput.trim())}
                        >
                            {saving ? 'Saving…' : 'Save key'}
                        </button>
                        {config.configured && (
                            <button
                                type="button"
                                className="hof-ai-settings-btn hof-ai-settings-btn--ghost"
                                disabled={saving}
                                onClick={() => save('')}
                            >
                                Clear key
                            </button>
                        )}
                    </div>
                    {error && <p className="hof-ai-settings-msg hof-ai-settings-msg--error">{error}</p>}
                    {success && <p className="hof-ai-settings-msg hof-ai-settings-msg--ok">{success}</p>}
                </div>
            </section>

            <section className="hof-ai-settings-section">
                <h3 className="hof-ai-settings-section-title">Cost &amp; privacy</h3>
                <ul className="hof-ai-settings-list">
                    <li>Each turn of an ask calls Anthropic and counts against your account credits. Typical cost: ~$0.0001–0.0005 per turn on Haiku 4.5.</li>
                    <li>The user's free-text turn (plus the constraints they've already accepted) is sent to Anthropic along with your facet schema. Customer products and PII are not sent.</li>
                    <li>You can revoke or rotate the key at <code>console.anthropic.com</code> at any time. Rotation only requires saving the new key here.</li>
                </ul>
            </section>
        </div>
    );
}
