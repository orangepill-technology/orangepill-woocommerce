/**
 * SubForm — renders extra fields required before payment intent creation.
 *
 * Nequi:     phone number  → metadata.phone_number
 * Daviplata: phone + ID    → metadata.phone_number / user_legal_id_type / user_legal_id
 * Card:      card fields   → browser-tokenized via Wompi (SAQ-A); only token reaches server
 *
 * Parent (OrangepillContent) owns formData state and passes onChange up.
 */

import { useState } from '@wordpress/element';

const config = window.orangepillBlocksConfig || {};
const i18n   = config.i18n || {};

const ID_TYPES = [
    { value: 'CC',  label: 'Cédula de Ciudadanía' },
    { value: 'CE',  label: 'Cédula de Extranjería' },
    { value: 'TI',  label: 'Tarjeta de Identidad' },
    { value: 'PP',  label: 'Pasaporte' },
    { value: 'NIT', label: 'NIT' },
];

function Field( { label, children } ) {
    return (
        <label className="op-subform-label">
            <span className="op-subform-label-text">{ label }</span>
            { children }
        </label>
    );
}

export function SubForm( { methodKey, formData, onChange } ) {
    if ( methodKey === 'wallet.nequi' ) {
        return (
            <div className="op-subform op-subform--nequi">
                <p className="op-subform-desc">
                    { i18n.nequi_desc || 'Te enviaremos una notificación push a tu app Nequi.' }
                </p>
                <Field label={ i18n.phone_number || 'Número de celular' }>
                    <input
                        type="tel"
                        className="op-subform-input"
                        inputMode="numeric"
                        autoComplete="tel-national"
                        placeholder={ i18n.phone_placeholder || 'Ej: 3001234567' }
                        value={ formData.phone_number || '' }
                        maxLength={ 10 }
                        onChange={ e =>
                            onChange( { ...formData, phone_number: e.target.value.replace( /\D/g, '' ).slice( 0, 10 ) } )
                        }
                    />
                </Field>
            </div>
        );
    }

    if ( methodKey === 'wallet.daviplata' ) {
        return (
            <div className="op-subform op-subform--daviplata">
                <p className="op-subform-desc">
                    { i18n.daviplata_desc || 'Te enviaremos una solicitud de cobro a tu app Daviplata.' }
                </p>
                <Field label={ i18n.phone_number || 'Número de celular' }>
                    <input
                        type="tel"
                        className="op-subform-input"
                        inputMode="numeric"
                        autoComplete="tel-national"
                        placeholder={ i18n.phone_placeholder || 'Ej: 3001234567' }
                        value={ formData.phone_number || '' }
                        maxLength={ 10 }
                        onChange={ e =>
                            onChange( { ...formData, phone_number: e.target.value.replace( /\D/g, '' ).slice( 0, 10 ) } )
                        }
                    />
                </Field>
                <Field label={ i18n.id_type || 'Tipo de documento' }>
                    <select
                        className="op-subform-select"
                        value={ formData.user_legal_id_type || '' }
                        onChange={ e => onChange( { ...formData, user_legal_id_type: e.target.value } ) }
                    >
                        <option value="">— Seleccionar —</option>
                        { ID_TYPES.map( t => (
                            <option key={ t.value } value={ t.value }>{ t.label }</option>
                        ) ) }
                    </select>
                </Field>
                <Field label={ i18n.id_number || 'Número de documento' }>
                    <input
                        type="text"
                        className="op-subform-input"
                        placeholder={ i18n.id_number_placeholder || 'Ej: 1234567890' }
                        value={ formData.user_legal_id || '' }
                        onChange={ e => onChange( { ...formData, user_legal_id: e.target.value } ) }
                    />
                </Field>
            </div>
        );
    }

    if ( methodKey === 'card' ) {
        return (
            <div className="op-subform op-subform--card">
                <p className="op-subform-desc">
                    { i18n.card_desc || 'Pago seguro con tarjeta. Tus datos van directamente a la pasarela.' }
                </p>
                <Field label={ i18n.card_number || 'Número de tarjeta' }>
                    <input
                        type="text"
                        className="op-subform-input"
                        inputMode="numeric"
                        autoComplete="cc-number"
                        placeholder="0000 0000 0000 0000"
                        value={ formData.card_number || '' }
                        onChange={ e => {
                            const raw = e.target.value.replace( /\D/g, '' ).slice( 0, 16 );
                            const fmt = raw.replace( /(.{4})/g, '$1 ' ).trim();
                            onChange( { ...formData, card_number: fmt } );
                        } }
                    />
                </Field>
                <div className="op-subform-row">
                    <Field label={ i18n.card_expiry || 'MM / AA' }>
                        <input
                            type="text"
                            className="op-subform-input"
                            inputMode="numeric"
                            autoComplete="cc-exp"
                            placeholder="MM / AA"
                            value={ formData.card_expiry || '' }
                            onChange={ e => {
                                const digits = e.target.value.replace( /\D/g, '' ).slice( 0, 4 );
                                const fmt    = digits.length >= 3
                                    ? digits.slice( 0, 2 ) + ' / ' + digits.slice( 2 )
                                    : digits;
                                onChange( { ...formData, card_expiry: fmt } );
                            } }
                        />
                    </Field>
                    <Field label={ i18n.card_cvc || 'CVC' }>
                        <input
                            type="text"
                            className="op-subform-input"
                            inputMode="numeric"
                            autoComplete="cc-csc"
                            placeholder="123"
                            value={ formData.card_cvc || '' }
                            onChange={ e =>
                                onChange( { ...formData, card_cvc: e.target.value.replace( /\D/g, '' ).slice( 0, 4 ) } )
                            }
                        />
                    </Field>
                </div>
                <Field label={ i18n.card_holder || 'Nombre del titular' }>
                    <input
                        type="text"
                        className="op-subform-input"
                        autoComplete="cc-name"
                        placeholder="Como aparece en la tarjeta"
                        value={ formData.card_holder || '' }
                        onChange={ e => onChange( { ...formData, card_holder: e.target.value } ) }
                    />
                </Field>
            </div>
        );
    }

    return null;
}
