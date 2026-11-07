<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $asunto }}</title>
</head>
<body style="margin:0; padding:0; background:#f3f4f6; font-family:Arial, Helvetica, sans-serif; color:#1f2937;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6; padding:24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px; background:#ffffff; border-radius:12px; overflow:hidden;">
                    <tr>
                        <td style="background:#0b1f4b; padding:20px 28px; color:#ffffff; font-size:18px; font-weight:bold;">
                            Clínica Santa Cruz
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:28px;">
                            <h1 style="margin:0 0 16px; font-size:20px; color:#0b1f4b;">{{ $asunto }}</h1>
                            <p style="margin:0 0 12px; font-size:15px; line-height:1.5;">{{ $saludo }}</p>

                            @foreach ($lineas as $linea)
                                <p style="margin:0 0 12px; font-size:15px; line-height:1.5;">{{ $linea }}</p>
                            @endforeach

                            @if ($datos)
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:16px 0; border-collapse:collapse; font-size:14px;">
                                    @foreach ($datos as $etiqueta => $valor)
                                        <tr>
                                            <td style="padding:8px 0; border-top:1px solid #e5e7eb; color:#6b7280; width:40%;">{{ $etiqueta }}</td>
                                            <td style="padding:8px 0; border-top:1px solid #e5e7eb; font-weight:bold;">{{ $valor }}</td>
                                        </tr>
                                    @endforeach
                                </table>
                            @endif

                            @if ($enlace)
                                <p style="margin:24px 0 0;">
                                    <a href="{{ $enlace }}" style="display:inline-block; background:#0b1f4b; color:#ffffff; text-decoration:none; padding:12px 20px; border-radius:8px; font-size:14px; font-weight:bold;">{{ $textoEnlace ?? 'Ver en la clínica' }}</a>
                                </p>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 28px; background:#f9fafb; color:#6b7280; font-size:12px; line-height:1.5;">
                            Este es un mensaje automático de la Clínica Santa Cruz. Por favor, no respondas a este correo.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
