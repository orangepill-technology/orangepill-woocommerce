/**
 * PaymentMethodSelector — renders the list of eligible payment methods.
 *
 * Replicates the renderOptions() logic from native-payment-shell.js in React.
 * Multi-channel methods (e.g. BRE-B with QR + Llave Dinámica) are expanded into
 * one row per channel, matching the native shell's behaviour.
 */

const config = window.orangepillBlocksConfig || {};
const i18n   = config.i18n || {};

function getSpeedLabel( speed ) {
    const map = {
        instant:  i18n.speed_instant  || 'Instant',
        same_day: i18n.speed_same_day || 'Same day',
        next_day: i18n.speed_next_day || 'Next day',
    };
    return map[ speed ] || '';
}

function getChannelLabel( channel ) {
    const map = {
        qr:        i18n.channel_qr        || 'QR',
        reference: i18n.channel_reference || 'Llave Dinámica',
        redirect:  'Redireccionado',
        embedded:  'Integrado',
    };
    return map[ channel ] || channel;
}

function buildRows( methods ) {
    const rows = [];
    ( methods || [] ).filter( m => m.eligible ).forEach( method => {
        const channels = method.channels || [];
        if ( channels.length > 1 ) {
            channels.forEach( ch => rows.push( {
                methodKey:      method.methodKey,
                channel:        ch,
                label:          method.label + ' (' + getChannelLabel( ch ) + ')',
                estimatedSpeed: method.estimatedSpeed,
            } ) );
        } else {
            rows.push( {
                methodKey:      method.methodKey,
                channel:        channels.length === 1 ? channels[ 0 ] : null,
                label:          method.label,
                estimatedSpeed: method.estimatedSpeed,
            } );
        }
    } );
    return rows;
}

export function PaymentMethodSelector( { methods, selected, selectedChannel, onChange } ) {
    const rows = buildRows( methods );

    if ( ! rows.length ) {
        return <p className="op-no-methods">{ i18n.no_methods || 'No payment methods available.' }</p>;
    }

    return (
        <div className="op-methods-list" role="radiogroup" aria-label={ i18n.select_method || 'Select a payment method' }>
            { rows.map( ( row, idx ) => {
                const id         = `op-method-${ row.methodKey }-${ row.channel || 'default' }-${ idx }`;
                const isSelected = selected === row.methodKey && selectedChannel === row.channel;
                return (
                    <label
                        key={ id }
                        className={ `op-method-item${ isSelected ? ' op-method-selected' : '' }` }
                        htmlFor={ id }
                    >
                        <input
                            type="radio"
                            id={ id }
                            name="op_method_key_ui"
                            value={ row.methodKey }
                            checked={ isSelected }
                            onChange={ () => onChange( row.methodKey, row.channel ) }
                            className="op-method-radio"
                        />
                        <span className="op-method-label">{ row.label }</span>
                        { row.estimatedSpeed && (
                            <span className={ `op-method-speed op-speed-${ row.estimatedSpeed }` }>
                                { getSpeedLabel( row.estimatedSpeed ) }
                            </span>
                        ) }
                    </label>
                );
            } ) }
        </div>
    );
}
