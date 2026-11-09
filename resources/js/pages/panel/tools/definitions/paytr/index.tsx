import { Form, Head } from '@inertiajs/react';
import { Save } from 'lucide-react';

import { Page, PageTitle, SectionCard } from '@/components/admin/page';
import {
    SelectField,
    SwitchField,
    TextField,
} from '@/components/shop/form-fields';
import { Button } from '@/components/ui/button';
import { FieldGroup } from '@/components/ui/field';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/lib/i18n';
import { edit, update } from '@/routes/panel/paytr/settings';

type Props = {
    settings: {
        enabled: boolean;
        merchant_id: string;
        has_key: boolean;
        has_salt: boolean;
        test_mode: boolean;
        max_installment: number;
        fee_payer: string;
    };
    notifyUrl: string;
};

const INSTALLMENTS = [
    { value: '0', label: t('Taksit yok (tek çekim)') },
    ...[2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12].map((n) => ({
        value: String(n),
        label: t('En fazla :n taksit', { n }),
    })),
];

export default function PaytrSettings({ settings, notifyUrl }: Props) {
    return (
        <>
            <Head title="PayTR" />

            <Page embedded>
                <PageTitle
                    compact
                    title="PayTR"
                    description={t(
                        'Kredi / banka kartıyla ödeme (PayTR iFrame, 3D Secure). Siparişlerde ve bayinin Cari Hesabım ekranındaki kartla cari ödemede kullanılır.',
                    )}
                />

                <Form
                    {...update.form()}
                    options={{ preserveScroll: true }}
                    className="flex flex-col gap-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <SectionCard title={t('Mağaza bilgileri')}>
                                <FieldGroup>
                                    <SwitchField
                                        name="enabled"
                                        label={t('PayTR ile kartla ödemeyi aç')}
                                        description={t(
                                            'Açık ve mağaza bilgileri girilmişse ödeme ekranında sunulur.',
                                        )}
                                        defaultChecked={settings.enabled}
                                    />
                                    <TextField
                                        name="merchant_id"
                                        label={t('Mağaza no (merchant_id)')}
                                        defaultValue={settings.merchant_id}
                                        error={errors.merchant_id}
                                    />
                                    <TextField
                                        name="merchant_key"
                                        label={t(
                                            'Mağaza parolası (merchant_key)',
                                        )}
                                        type="password"
                                        placeholder={
                                            settings.has_key
                                                ? t(
                                                      '•••••••• (kayıtlı; değiştirmek için yazın)',
                                                  )
                                                : ''
                                        }
                                        error={errors.merchant_key}
                                    />
                                    <TextField
                                        name="merchant_salt"
                                        label={t(
                                            'Mağaza gizli anahtarı (merchant_salt)',
                                        )}
                                        type="password"
                                        placeholder={
                                            settings.has_salt
                                                ? t(
                                                      '•••••••• (kayıtlı; değiştirmek için yazın)',
                                                  )
                                                : ''
                                        }
                                        error={errors.merchant_salt}
                                    />
                                    <SwitchField
                                        name="test_mode"
                                        label={t('Test modu')}
                                        description={t(
                                            'PayTR test kartlarıyla deneme ödemesi alınır; canlıya geçince kapatın.',
                                        )}
                                        defaultChecked={settings.test_mode}
                                    />
                                    <p className="text-sm text-muted-foreground">
                                        {t(
                                            "PayTR mağaza panelinde Bildirim URL'si olarak şunu tanımlayın:",
                                        )}{' '}
                                        <code className="rounded bg-muted px-1.5 py-0.5 text-xs break-all">
                                            {notifyUrl}
                                        </code>
                                    </p>
                                </FieldGroup>
                            </SectionCard>

                            <SectionCard title={t('Taksit')}>
                                <FieldGroup>
                                    <SelectField
                                        name="max_installment"
                                        label={t('Taksit')}
                                        options={INSTALLMENTS}
                                        defaultValue={String(
                                            settings.max_installment,
                                        )}
                                        error={errors.max_installment}
                                    />
                                    <SelectField
                                        name="fee_payer"
                                        label={t('Taksit farkını kim öder')}
                                        options={[
                                            {
                                                value: 'customer',
                                                label: t(
                                                    'Müşteri (vade farkı ödeme tutarına eklenir)',
                                                ),
                                            },
                                            {
                                                value: 'merchant',
                                                label: t(
                                                    'Firma (müşteri sipariş tutarını öder)',
                                                ),
                                            },
                                        ]}
                                        defaultValue={settings.fee_payer}
                                        error={errors.fee_payer}
                                        description={t(
                                            "PayTR panelinizdeki taksit ayarıyla aynı olmalıdır; ödeme ekranındaki taksit tablosu buna göre hesaplanır. Müşterinin ödediği fark siparişte 'vade farkı' olarak görünür.",
                                        )}
                                    />
                                </FieldGroup>
                            </SectionCard>

                            <div className="flex justify-end">
                                <Button type="submit" disabled={processing}>
                                    {processing ? (
                                        <Spinner data-icon="inline-start" />
                                    ) : (
                                        <Save data-icon="inline-start" />
                                    )}
                                    {t('Kaydet')}
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </Page>
        </>
    );
}

PaytrSettings.layout = {
    breadcrumbs: [{ title: 'PayTR', href: edit() }],
};
