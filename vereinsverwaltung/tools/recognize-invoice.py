"""Local bounded invoice text extraction. No network, no shell, no document actions."""
import json, os, re, sys, time, subprocess, pathlib
class RecognitionError(Exception): pass
BASE=pathlib.Path(sys.argv[1]).resolve() if len(sys.argv)>1 else None; DEADLINE=time.monotonic()+80

def execute(args):
    remaining=min(22,max(1,int(DEADLINE-time.monotonic())))
    if time.monotonic()>DEADLINE: raise RecognitionError('Zeitlimit der lokalen Erkennung erreicht.')
    output=BASE/'command.out'
    with output.open('wb') as out:
        p=subprocess.run(args,stdin=subprocess.DEVNULL,stdout=out,stderr=subprocess.DEVNULL,timeout=remaining,shell=False,cwd=BASE,env=dict(os.environ,OMP_THREAD_LIMIT='1',OMP_NUM_THREADS='1'))
    if p.returncode or output.stat().st_size>1000000: raise RecognitionError('Datei konnte nicht sicher gelesen werden.')
    return output.read_text('utf-8',errors='replace')

def tool(config,key):
    value=config.get(key,'')
    if not isinstance(value,str) or not os.path.isabs(value) or not os.path.isfile(value): raise RecognitionError('Lokale Texterkennung ist nicht vollständig eingerichtet.')
    return value

def tesseract_command(config, image):
    command=[tool(config,'tesseract')]
    if config.get('tesseract_loader'):
        library=config.get('tesseract_library_path','')
        if not isinstance(library,str) or not os.path.isabs(library) or not os.path.isdir(library):
            raise RecognitionError('Lokale Texterkennung ist nicht vollständig eingerichtet.')
        # Use the bundled loader explicitly: its ELF interpreter cannot carry a
        # path that is simultaneously valid inside the SSH chroot and PHP-FPM.
        command=[tool(config,'tesseract_loader'),'--inhibit-rpath','','--library-path',library,*command]
    command += [str(image),'stdout','-l',config.get('language','deu+eng'),'--psm','3']
    if config.get('tessdata'): command += ['--tessdata-dir',config['tessdata']]
    return command

def number(value):
    value=value.strip().replace(' ','')
    if ',' in value: value=value.replace('.','').replace(',','.')
    return value

def fields_from_text(text, header_hints=()):
    text=text.replace('\u00a0',' ').replace('\ufffe','-'); lines=[x.strip() for x in text.splitlines() if x.strip()]; fields={}; warnings=['Alle erkannten Angaben sind ungeprüfte Vorschläge. Bankdaten und Beträge am Original kontrollieren.']
    patterns={
      'Rechnungsnummer':r'(?:Rechnung(?:s(?:nummer|[ -]?nr\.?|[ -]?no\.?)|[ -]+Nr\.?)|Invoice\s*(?:number|no\.?))[ \t]*[:#]?[ \t]*([A-Z0-9][A-Z0-9/._-]{1,80})',
      'Rechnungsdatum':r'\b(?:Rechnungsdatum|Invoice date|Datum)\s*:?\s*(\d{1,2}\.\d{1,2}\.\d{4}|\d{4}-\d{2}-\d{2})',
      'Fälligkeit':r'(?:Fällig(?:keit| am)?|Zahlbar bis|Due date)\s*:?\s*(\d{1,2}\.\d{1,2}\.\d{2}(?:\d{2})?|\d{4}-\d{2}-\d{2})',
    }
    for label,pattern in patterns.items():
        matches=list(dict.fromkeys(m.strip() for m in re.findall(pattern,'\n'.join([text,*header_hints]),re.I)))
        if label=='Rechnungsnummer': matches=[m for m in matches if re.search(r'\d',m)]
        if len(matches)==1:
            val=matches[0]
            if label in ['Rechnungsdatum','Fälligkeit']:
                import datetime
                if label=='Fälligkeit' and re.fullmatch(r'\d{1,2}\.\d{1,2}\.\d{2}',val):
                    reference=fields.get('Rechnungsdatum','')
                    if not reference: continue
                    val=val[:-2]+reference[:2]+val[-2:]
                    if abs(int(val[-4:])-int(reference[:4]))>2: continue
                try: val=datetime.datetime.strptime(val,'%d.%m.%Y' if '.' in val else '%Y-%m-%d').date().isoformat()
                except ValueError: continue
            elif label in ['Rechnungsbetrag brutto','Umsatzsteuerbetrag']: val=number(val)
            fields[label]=val
        elif len(matches)>1: warnings.append(label+': mehrere Angaben erkannt, bitte selbst auswählen.')
    # Label/value columns must not be zipped by ordinal position. Native PDF
    # extraction supplies only values found on the label's actual visual row.
    payment_methods=list(dict.fromkeys(match[1].strip() for line in lines if (match:=re.fullmatch(r'(?:Zahlungsart|Zahlungsbedingung|Bezahlung durch)[ \t]*:[ \t]*(.{1,120})',line,re.I))))
    if len(payment_methods)==1:
        fields['Zahlungsart']=payment_methods[0]
        if re.search(r'\b(?:Klarna|PayPal)\b',payment_methods[0],re.I): warnings.append('Zahlungsart laut Original: '+payment_methods[0]+'. Separate Zahlungsinformationen beachten; allgemeine Bankverbindungen nicht als Zahlungsanweisung übernehmen. Diese Angabe bestätigt keine Zahlung.')
    elif len(payment_methods)>1: warnings.append('Mehrere Zahlungsarten genannt; Zahlungsanweisung am Original prüfen. Keine Zahlung wird dadurch bestätigt.')
    invoice_tax_rates=[]
    monetary={'Rechnungsbetrag brutto':[], 'Rechnungsbetrag netto':[], 'Umsatzsteuerbetrag':[]}
    money=r'(?<![\d.,])([0-9]+(?:[. ][0-9]{3})*[,\.]\d{2})(?![\d.,])'
    for line in lines:
        label=None
        if re.search(r'\bTotal\s+EUR\s+inkl\.?\s+MwSt\.',line,re.I): label='Rechnungsbetrag brutto'
        elif re.match(r'^(?:Rechnungsbetrag\s+netto|Gesamt ohne MwSt\.?|Gesamt[ -]?Netto|Nettobetrag|Netto(?:summe|betrag)?|Total excl\.? VAT)\b',line,re.I): label='Rechnungsbetrag netto'
        elif re.match(r'^(?:Gesamt[ -]?Brutto|Gesamtbetrag(?:\s+brutto)?|Rechnungsbetrag(?:\s+brutto)?|Bruttobetrag|Total incl\.? VAT|Grand total)\b',line,re.I): label='Rechnungsbetrag brutto'
        elif re.match(r'^(?:zzgl\.?\s*)?(?:\d{1,2}(?:[,.]\d+)?\s*%\s*)?(?:MwSt\.?|Umsatzsteuer|VAT)\b',line,re.I):
            label='Umsatzsteuerbetrag'
            rate=re.match(r'^(?:zzgl\.?\s*)?(\d{1,2})\s*%',line,re.I)
            if rate: invoice_tax_rates.append(rate[1])
        if label:
            # Parenthesized tax basis/year and percent are not payable VAT.
            value_line=re.sub(r'\([^)]*\)','',line)
            values=re.findall(money,value_line)
            if len(values)==1: monetary[label].append(number(values[0]))
    for index,line in enumerate(lines[:-1]):
        if re.match(r'^MwSt\.?(?:\s+)Netto-Betrag\s+MwSt\.?-Betrag$',line,re.I):
            row=re.fullmatch(r'(\d{1,2})\s*%\s+('+money+r')\s+('+money+r')',lines[index+1])
            if row: monetary['Rechnungsbetrag netto'].append(number(row[2]));monetary['Umsatzsteuerbetrag'].append(number(row[4]));invoice_tax_rates.append(row[1])
    for label,values in monetary.items():
        values=list(dict.fromkeys(values))
        if len(values)==1: fields[label]=values[0]
        elif len(values)>1: warnings.append(label+': mehrere Angaben erkannt, bitte selbst auswählen.')
    if 'Rechnungsdatum' not in fields:
        dates=[]
        for line in lines:
            if re.search(r'\bRechnung\s+Nr',line,re.I): dates += re.findall(r'\b\d{1,2}\.\d{1,2}\.\d{4}\b',line)
        if len(set(dates))==1:
            import datetime
            try: fields['Rechnungsdatum']=datetime.datetime.strptime(dates[0],'%d.%m.%Y').date().isoformat()
            except ValueError: pass
    # Prefer labelled senders and complete sender address lines. A buyer/shipping
    # block or a bank name is never evidence of the invoice issuer.
    recipient_marker=r'^(?:Rechnung(?:s(?:empfänger|adresse))?|Lieferadresse|Lieferanschrift|Empfänger|Kunde|Bill to|Ship to)(?:\s+an)?\s*[:]|^(?:Rechnung an|Lieferung an|Empfänger|Bill to|Ship to)\b'
    def recipient_context(index):
        for prior in range(max(0,index-4),index+1):
            previous=lines[prior]
            if re.search(recipient_marker,previous,re.I):
                # A complete, delimited one-line shipping address does not
                # label the following independent sender letterhead as buyer.
                if prior<index and len(re.split(r'[|·]',previous))>=3 and re.search(r'\b\d{5}\b',previous): continue
                return True
        return False
    explicit=[]
    for index,line in enumerate(lines):
        found=re.match(r'(?:Rechnungssteller|Lieferant|Verkäufer|Supplier)\s*:\s*(.{2,150})$',line,re.I)
        if found and not re.search(recipient_marker,line,re.I): explicit.append(found[1])
    explicit=list(dict.fromkeys(explicit))
    letterheads=[]
    for index,line in enumerate(lines):
        if recipient_context(index): continue
        # OCR often turns centred dots into spaced hyphens. Unspaced hyphens
        # remain part of names/street names, not address delimiters.
        parts=[part.strip() for part in re.split(r'[·|]|\s+[-–—]\s+',line)]
        if len(parts)>=3 and 2<=len(parts[0])<=150 and re.search(r'\d',parts[1]) and re.fullmatch(r'(?:D-)?\d{5}\s+[^\d]{2,100}',parts[2]):
            if not re.search(r'\b(?:IBAN|BIC|Bankverbindung|Rechnungsempfänger|Lieferadresse)\b',parts[0],re.I):
                letterheads.append((parts[0],'\n'.join(parts[1:3])))
    candidates=list(dict.fromkeys(name for name,address in letterheads))
    if len(explicit)==1:
        fields['Rechnungssteller']=explicit[0]
    elif len(explicit)>1:
        warnings.append('Mehrere Rechnungssteller genannt; Firma und Anschrift bitte am Original auswählen.')
    elif len(candidates)==1:
        fields['Rechnungssteller']=candidates[0]
        warnings.append('Firmenname aus dem Briefkopf vorgeschlagen; Absender am Original prüfen.')
    elif len(candidates)>1:
        warnings.append('Mehrere Absenderzeilen erkannt; Firma und Anschrift bitte am Original auswählen.')
    if 'Rechnungssteller' in fields:
        addresses=list(dict.fromkeys(address for name,address in letterheads if name==fields['Rechnungssteller']))
        if len(addresses)==1: fields['Anschrift Rechnungssteller']=addresses[0]
        elif len(addresses)>1: warnings.append('Mehrere Anschriften zum Rechnungssteller erkannt; bitte selbst auswählen.')
        else:
            # Only a directly labelled seller may use subsequent address lines;
            # do not scan onward into an unlabelled purchaser address block.
            for index,line in enumerate(lines):
                if not re.match(r'(?:Rechnungssteller|Lieferant|Verkäufer|Supplier)\s*:',line,re.I) or fields['Rechnungssteller'] not in line: continue
                block=[]
                for following in lines[index+1:index+4]:
                    if re.search(recipient_marker,following,re.I): break
                    block.append(following)
                    if re.fullmatch(r'(?:D-)?\d{5}\s+[^\d]{2,100}',following):
                        if len(block)>=2 and re.search(r'\d',block[0]): fields['Anschrift Rechnungssteller']='\n'.join(block)
                        break
    supplier=fields.get('Rechnungssteller','')
    supplier_lines=[i for i,line in enumerate(lines) if supplier and supplier.casefold() in line.casefold()]
    contacts={'E-Mail Rechnungssteller':[], 'Telefon Rechnungssteller':[]}
    email_pattern=r"(?<![\w@])[A-Z0-9.!#$%&'*+/=?^_`{|}~-]+@[A-Z0-9](?:[A-Z0-9.-]*[A-Z0-9])?\.[A-Z]{2,63}(?![\w@])"
    for index,line in enumerate(lines):
        if recipient_context(index) or not any(0<=index-source<=4 and not any(re.search(r'^(?:Geschäftsführer|Aufsichtsrat|Registergericht|Bankverbindungen)\s*:',between,re.I) for between in lines[source+1:index+1]) for source in supplier_lines): continue
        contacts['E-Mail Rechnungssteller'] += re.findall(email_pattern,line,re.I)
        for match in re.finditer(r'\b(?:Telefon|Tel\.?)[ \t]*:?[ \t]*(\+?\d[\d ()/.-]{4,40}\d)',line,re.I):
            value=match[1].strip()
            if 6<=len(re.sub(r'\D','',value))<=18: contacts['Telefon Rechnungssteller'].append(value)
    for label,values in contacts.items():
        values=list(dict.fromkeys(values))
        if len(values)==1: fields[label]=values[0]
        elif len(values)>1: warnings.append(label+': mehrere Angaben erkannt, bitte selbst auswählen.')
    # Keep every checksum-valid account, but never combine columns by ordinal
    # position. A BIC/name belongs to an IBAN only when that line is unambiguous.
    bank_accounts=[];bank_occurrences={}
    iban_lengths={'DE':22,'AT':20,'CH':21,'GB':22,'NL':18,'BE':16,'FR':27,'IT':27,'ES':24,'PL':28,'DK':18,'NO':15,'FI':18,'LU':20,'PT':25,'IE':22,'SE':24,'HU':28,'CZ':24,'SK':24,'RO':24,'GR':27}
    bic_pattern=r'\bBIC(?:\s*\(SWIFT\)|/SWIFT)?\s*:?\s*([A-Z]{6}[A-Z0-9]{2}(?:[A-Z0-9]{3})?)\b'
    for index,line in enumerate(lines):
        if recipient_context(index): continue
        accounts=[]
        for match in re.finditer(r'\b[A-Z]{2}\d{2}(?:[ ]?[A-Z0-9]){11,34}',line):
            candidate=match[0].replace(' ','');sizes=[iban_lengths[candidate[:2]]] if candidate[:2] in iban_lengths else range(min(len(candidate),34),14,-1)
            for size in sizes:
                if len(candidate)<size:continue
                if len(candidate)>size and candidate[size].isdigit():continue
                raw=candidate[:size];digits=''.join(str(ord(char)-55) if char.isalpha() else char for char in raw[4:]+raw[:4])
                if digits.isdigit() and int(digits)%97==1:
                    accounts.append(raw);break
        accounts=list(dict.fromkeys(accounts))
        bics=list(dict.fromkeys(re.findall(bic_pattern,line,re.I)))
        prefix=re.split(r'\b(?:BLZ|Bankleitzahl|Konto(?:nummer)?|Kto\.?|BIC|IBAN)\b',line,maxsplit=1,flags=re.I)[0].strip(' ()-·|,:')
        name=prefix if len(prefix)<=150 and re.search(r'Bank|Sparkasse|Raiffeisen',prefix,re.I) and not re.search(r'\d',prefix) else ''
        for iban in accounts:
            if iban not in bank_occurrences:bank_occurrences[iban]=[]
            bank_occurrences[iban].append((bics[0].upper() if len(accounts)==1 and len(bics)==1 else '',name if len(accounts)==1 else ''))
    for iban,occurrences in bank_occurrences.items():
        bics=set(bic for bic,name in occurrences if bic);names=set(name for bic,name in occurrences if name)
        if len(bics)>1 or len(names)>1: warnings.append('Widersprüchliche Bankangaben zu einem Konto; BIC und Bankname am Original prüfen.')
        bank_accounts.append({'iban':iban,'bic':next(iter(bics)) if len(bics)==1 else '', 'bank_name':next(iter(names)) if len(names)==1 else ''})
    if len(bank_accounts)==1 and not re.search(r'\b(?:Klarna|PayPal)\b',fields.get('Zahlungsart',''),re.I):
        fields['IBAN']=bank_accounts[0]['iban']
        if bank_accounts[0]['bic']: fields['BIC']=bank_accounts[0]['bic']
    elif len(bank_accounts)>1: warnings.append('Mehrere gültige Bankkonten erkannt; Konto ausdrücklich auswählen und am Original prüfen.')
    currencies=set(re.findall(r'\b(?:EUR|USD|GBP|CHF)\b',text))
    if '€' in text: currencies.add('EUR')
    if len(currencies)==1: fields['Währung']=next(iter(currencies))
    fields['Belegart']='Gutschrift' if re.search(r'\b(?:Gutschrift|Credit note)\b',text,re.I) else ('Rechnung' if re.search(r'\b(?:Rechnung|Invoice)\b',text,re.I) else 'Unbekannt')
    items=[]
    # Only tabular lines with explicit position, quantity, unit price and final amount.
    for line in lines:
        m=re.match(r'^(\d{1,4})\s+(.{2,180}?)\s+(\d+(?:[,.]\d+)?)\s*(Stk\.?|Stück|Satz|Sätze|Set|m|kg|l)?\s+([\d.]+,\d{2}|\d+\.\d{2})\s+(?:(\d{1,2})\s*%\s+)?([\d.]+,\d{2}|\d+\.\d{2})(?:\s*(?:EUR|€))?$',line,re.I)
        if m:
            items.append({'number':m[1],'name':m[2],'quantity':number(m[3]),'unit':m[4] or '', 'unit_price':number(m[5]),'net_amount':'','gross_amount':'','line_amount':number(m[7]),'tax_rate':m[6] or '', 'tax_category':'','price_base_quantity':'','ambiguous':True,'uncertain':True,'original_line':line})
    if not items:
        # PDF text streams sometimes split description and numeric columns across lines.
        pending=None
        numeric=r'(\d+(?:[,.]\d+)?)\s+\((\d{1,2}(?:[,.]\d+)?)\s*%\)\s+([\d.]+,\d{2}|\d+\.\d{2})\s*(?:EUR|€)?\s+([\d.]+,\d{2}|\d+\.\d{2})\s*(?:EUR|€)?'
        for line in lines:
            full=re.match(r'^(\d{1,4})\s+(.{2,240}?)\s+'+numeric+r'$',line,re.I)
            if full: parts=(full[1],full[2],full[3],full[4],full[5],full[6]);pending=None
            else:
                n=re.fullmatch(numeric,line,re.I)
                if n and pending: parts=(*pending,n[1],n[2],n[3],n[4]);pending=None
                else:
                    title=re.match(r'^(\d{1,4})\s+([^\d].{1,180})$',line)
                    if title: pending=(title[1],title[2])
                    elif re.match(r'^(?:Gesamt|Rechnung|Netto|Brutto)',line,re.I): pending=None
                    continue
            items.append({'number':parts[0],'name':parts[1],'quantity':number(parts[2]),'unit':'','unit_price':number(parts[4]),'net_amount':'','gross_amount':'','line_amount':number(parts[5]),'tax_rate':number(parts[3]),'tax_category':'','price_base_quantity':'','ambiguous':True,'uncertain':True,'original_line':line})
    if not items and re.search(r'Einzelpreis\s+Brutto\s*Betrag\s*\(Brutto\)',text,re.I):
        # A common PDF reading order is quantities/prices, then description, then position/article ID.
        for index,line in enumerate(lines[:-2]):
            values=re.fullmatch(r'(\d+(?:[,.]\d+)?)\s+(Stück|Stk\.?|Satz|Sätze|Set)\s+([\d.]+,\d{2})\s+([\d.]+,\d{2})',line,re.I)
            position=re.match(r'^(\d{1,4}(?:\.\d{1,3})?)\s+([A-Z0-9][A-Z0-9-]{3,})(?:\s+.{1,100})?$',lines[index+2])
            description=lines[index+1]
            if not values or not position or not 2<=len(description)<=240: continue
            items.append({'number':position[1],'name':description,'quantity':number(values[1]),'unit':values[2],'unit_price':number(values[3]),'net_amount':'','gross_amount':number(values[4]),'line_amount':number(values[4]),'price_basis':'gross','tax_rate':'','tax_category':'','price_base_quantity':'','ambiguous':False,'uncertain':True,'original_line':' | '.join(lines[index:index+3])})
        # Unpriced dotted children belong to the explicitly priced .0 package.
        # Keep their quantities and names without inventing prices or charging
        # them twice. The current form reads name and original_line directly.
        package_components={}
        for index,line in enumerate(lines[:-2]):
            quantity=re.fullmatch(r'(\d+(?:[,.]\d+)?)\s+(Stück|Stk\.?|Satz|Sätze|Set)',line,re.I)
            child=re.fullmatch(r'(\d{1,4}\.\d{1,3})\s+([A-Z0-9][A-Z0-9-]{3,})(?:\s+(.{1,100}))?',lines[index+2])
            if quantity and child and not child[1].endswith('.0') and 2<=len(lines[index+1])<=240:
                package_components.setdefault(child[1].split('.')[0],[]).append({'number':child[1],'name':lines[index+1],'quantity':number(quantity[1]),'unit':quantity[2],'article_id':child[2],'brand':child[3] or ''})
        for item in items:
            if not item['number'].endswith('.0'): continue
            components=package_components.get(item['number'].split('.')[0],[])
            if components:
                item['components']=components
                detail='Paketbestandteile ohne Einzelpreis: '+'; '.join(c['number']+': '+c['quantity']+' '+c['unit']+' '+c['name'] for c in components)
                item['original_line']+=' | '+detail
                short='; '.join(c['quantity']+'× '+c['name'] for c in components)
                item['name']=(item['name']+' (Paket: '+short+')')[:200]
    if not items:
        # Labelled single-article invoices may have no position-number column.
        # A NET value is only classified when it exactly reconciles to the
        # explicit invoice net/VAT/gross totals; otherwise keep it ambiguous.
        articles=[index for index,line in enumerate(lines) if re.match(r'^Artikel\s*:',line,re.I)]
        if len(articles)==1:
            index=articles[0];block=[]
            for line in lines[index+1:index+14]:
                if re.match(r'^(?:Rechnungsbetrag|Gesamtbetrag|Bezahlung|Zahlungsziel)\b',line,re.I): break
                block.append(line)
            article=re.match(r'^Artikel\s*:\s*(.{2,500}?)\s+('+money+r')\s*(?:EUR|€)$',lines[index],re.I)
            quantities=[re.fullmatch(r'Stück\s*:\s*(\d{1,6})(?:\s*[x×]\s*(\d{1,6}))?\s+(Stück|Stk\.?|Satz|Sätze|Set)',line,re.I) for line in block]
            quantities=[q for q in quantities if q]
            if article and len(quantities)==1:
                from decimal import Decimal,ROUND_HALF_UP
                q=quantities[0];quantity=str(int(q[1])*int(q[2] or '1'));amount=number(article[2]);rate=next(iter(set(invoice_tax_rates))) if len(set(invoice_tax_rates))==1 else ''
                net=fields.get('Rechnungsbetrag netto','');gross=fields.get('Rechnungsbetrag brutto','');vat=fields.get('Umsatzsteuerbetrag','')
                explicit_net=bool(net and gross and vat and rate and Decimal(amount)==Decimal(net) and Decimal(net)+Decimal(vat)==Decimal(gross) and (Decimal(net)*Decimal(rate)/100).quantize(Decimal('.01'),rounding=ROUND_HALF_UP)==Decimal(vat))
                if int(quantity)>0: items.append({'number':'1','name':article[1][:200],'quantity':quantity,'unit':q[3],'unit_price':amount if quantity=='1' else '', 'net_amount':amount if explicit_net else '', 'gross_amount':gross if explicit_net else '', 'line_amount':amount,'price_basis':'net' if explicit_net else '', 'tax_rate':rate,'tax_category':'','price_base_quantity':'','ambiguous':not explicit_net,'uncertain':True,'original_line':' | '.join([lines[index],*block])})
    if len(set(invoice_tax_rates))==1:
        for item in items:
            if not item['tax_rate']: item['tax_rate']=invoice_tax_rates[0]
    if items and 'Rechnungsbetrag brutto' in fields:
        from decimal import Decimal,InvalidOperation
        try:
            total=sum(Decimal(i['gross_amount'] if not i.get('ambiguous',True) and i.get('gross_amount') else i['line_amount']) for i in items)
            if total!=Decimal(fields['Rechnungsbetrag brutto']): warnings.append('Positionssumme weicht vom Rechnungsbetrag ab. Netto/Brutto, Rabatte und Rundungen am Original prüfen; keine automatische Korrektur.')
        except (InvalidOperation,KeyError): pass
    if not items: warnings.append('Keine eindeutig gegliederten Positionszeilen erkannt. Artikel und Einzelpreise bitte am Original eintragen.')
    return {'fields':fields,'bank_accounts':bank_accounts,'items':items[:100],'items_truncated':len(items)>100,'warnings':warnings}


def pdf_header_hints(textpage):
    """Read only exact invoice labels, on their visual row, never column order."""
    hints=[]
    for label in ('Rechnungsnummer','Rechnungsdatum'):
        search=textpage.search(label+':',match_whole_word=True)
        try:
            for _ in range(8):
                match=search.get_next()
                if not match: break
                boxes=[textpage.get_charbox(index) for index in range(match[0],match[0]+match[1])]
                bottom=min(box[1] for box in boxes);top=max(box[3] for box in boxes);right=max(box[2] for box in boxes)
                if not 2<=top-bottom<=25: continue
                value=textpage.get_text_bounded(left=right+1,bottom=bottom-1,right=right+260,top=top+1).strip()
                pattern=r'\d{1,2}\.\d{1,2}\.\d{4}|\d{4}-\d{2}-\d{2}' if label=='Rechnungsdatum' else r'[A-Z0-9][A-Z0-9/._-]{1,80}'
                if re.fullmatch(pattern,value,re.I) and re.search(r'\d',value): hints.append(label+': '+value)
        finally: search.close()
    return hints


def raster_scale(width, height):
    # PDF coordinates are points (72/in); target 300 dpi without relaxing limits.
    import math
    if not math.isfinite(width) or not math.isfinite(height) or width <= 0 or height <= 0:
        raise RecognitionError('Ungültige PDF-Seitengröße.')
    return min(300 / 72, 3508 / max(width, height), math.sqrt(15000000 / (width * height)))


def prepare_ocr_image(image):
    from PIL import Image, ImageOps
    if image.width <= 0 or image.height <= 0 or image.width * image.height > 20000000:
        raise RecognitionError('Bild hat zu viele Pixel (maximal 20 Millionen).')
    oriented = ImageOps.exif_transpose(image)
    try:
        oriented.thumbnail((3508, 3508), Image.Resampling.LANCZOS)
        # Transparent invoice backgrounds must become white rather than black.
        rgba = oriented.convert('RGBA')
        try:
            clean = Image.new('RGB', rgba.size, 'white')
            clean.paste(rgba, mask=rgba.getchannel('A'))
            return clean
        finally:
            rgba.close()
    finally:
        oriented.close()


def main():
    request=json.load(sys.stdin);config=request['runtime'];mime=request['mime'];source=BASE/'input.bin'
    from invoice_ocr_sandbox import activate
    activate(BASE,config)
    if source.stat().st_size>10485760 or mime not in ['application/pdf','image/jpeg','image/png','image/heic','image/heif']: raise RecognitionError('Dateigröße oder Dateityp für Texterkennung nicht zulässig.')
    try:
        import resource
        resource.setrlimit(resource.RLIMIT_CPU,(70,75));resource.setrlimit(resource.RLIMIT_FSIZE,(64*1024*1024,64*1024*1024));resource.setrlimit(resource.RLIMIT_AS,(1024*1024*1024,1024*1024*1024))
    except ImportError: pass
    if mime=='application/pdf':
        from invoice_embedded_xml import extract
        try: embedded=extract(source.read_bytes())
        except ValueError as error: raise RecognitionError(str(error)) from error
        if request.get('mode')=='embedded_xml' or embedded.get('embedded'): return embedded
    texts=[];header_hints=[];used_ocr=False;used_text=False
    if mime=='application/pdf':
        import pypdfium2 as pdfium
        with pdfium.PdfDocument(source.read_bytes()) as pdf:
            if not 1<=len(pdf)<=6: raise RecognitionError('Für die Texterkennung höchstens sechs PDF-Seiten verwenden.')
            for index in range(len(pdf)):
                if time.monotonic()>DEADLINE: raise RecognitionError('Zeitlimit der lokalen Erkennung erreicht.')
                page=pdf[index]
                try:
                    textpage=page.get_textpage()
                    try:
                        value=textpage.get_text_range()
                        if len(re.sub(r'\s','',value))>=40:
                            try: header_hints.extend(pdf_header_hints(textpage))
                            except Exception: pass  # Optional header evidence must not prevent manual review.
                    finally: textpage.close()
                    if len(re.sub(r'\s','',value))>=40: texts.append(value);used_text=True;continue
                    width,height=page.get_size()
                    if width<=0 or height<=0: raise RecognitionError('Ungültige PDF-Seitengröße.')
                    bitmap=page.render(scale=raster_scale(width,height))
                    try:
                        with bitmap.to_pil() as pixels:
                            with prepare_ocr_image(pixels) as prepared: prepared.save(BASE/'page.png', dpi=(300,300))
                    finally: bitmap.close()
                finally: page.close()
                ocr=tesseract_command(config,BASE/'page.png')
                texts.append(execute(ocr));used_ocr=True;(BASE/'page.png').unlink(missing_ok=True)
    else:
        from PIL import Image
        if mime in ['image/heic','image/heif']:
            from heic_invoice_image import decode
            img=decode(source,config)
        else:
            img=Image.open(source,formats=['JPEG' if mime=='image/jpeg' else 'PNG'])
        with img:
            if img.width*img.height>20000000: raise RecognitionError('Bild hat zu viele Pixel (maximal 20 Millionen).')
            if request.get('mode')=='preview':
                if mime not in ['image/heic','image/heif']: raise RecognitionError('Keine HEIC-Vorschau.')
                import io,base64
                img.thumbnail((1600,1600));output=io.BytesIO();img.convert('RGB').save(output,format='JPEG',quality=80)
                jpeg=output.getvalue()
                if len(jpeg)>1500000: raise RecognitionError('Vorschau ist zu groß.')
                return {'state':'ready','preview_base64':base64.b64encode(jpeg).decode('ascii')}
            with prepare_ocr_image(img) as prepared: prepared.save(BASE/'page.png', dpi=(300,300))
        ocr=tesseract_command(config,BASE/'page.png')
        texts.append(execute(ocr));used_ocr=True
    text='\n\n'.join(texts)
    if len(text)>180000:raise RecognitionError('Erkannter Text überschreitet die sichere Obergrenze.')
    if not text.strip():raise RecognitionError('Kein lesbarer Text erkannt. Bitte eine schärfere Vorlage verwenden.')
    return {'state':'ready','method':'mixed' if used_ocr and used_text else ('ocr' if used_ocr else 'text'),'text':text,**fields_from_text(text,header_hints)}
if __name__=='__main__':
    try: result=main()
    except Exception as error:
        safe=str(error) if isinstance(error,RecognitionError) else 'Datei konnte nicht sicher erkannt werden. Bitte Original manuell prüfen.'
        result={'state':'failed','error':safe,'fields':{},'items':[]}
    print(json.dumps(result,ensure_ascii=False))
