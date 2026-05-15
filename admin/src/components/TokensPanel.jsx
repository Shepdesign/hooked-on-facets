export default function TokensPanel({ tokens }) {
    const entries = Object.entries(tokens || {});

    return (
        <section className="hof-tokens">
            <header className="hof-tokens-header">
                <h2>CSS variable tokens</h2>
                <p>
                    These tokens drive every HOF UI surface — admin and public. Editor coming in
                    Phase 2; for now, override via theme CSS or the{' '}
                    <code>hof_admin_css_tokens</code> PHP filter.
                </p>
            </header>

            {entries.length === 0 ? (
                <p className="hof-tokens-empty">No tokens registered.</p>
            ) : (
                <ul className="hof-tokens-list">
                    {entries.map(([name, value]) => (
                        <li key={name} className="hof-tokens-item">
                            <span className="hof-tokens-swatch" style={swatchStyle(value)} aria-hidden />
                            <code className="hof-tokens-name">{name}</code>
                            <code className="hof-tokens-value">{String(value)}</code>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

function swatchStyle(value) {
    const v = String(value);
    if (v.startsWith('#') || v.startsWith('rgb') || v.startsWith('hsl')) {
        return { backgroundColor: v, border: '1px solid var(--hof-border)' };
    }
    return {
        background:
            'repeating-linear-gradient(45deg, var(--hof-bg), var(--hof-bg) 4px, var(--hof-border) 4px, var(--hof-border) 8px)',
    };
}
