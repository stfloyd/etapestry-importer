import os
import sys
import json
import csv
from pathlib import Path

import smtplib
import os.path as op
from email.mime.multipart import MIMEMultipart
from email.mime.base import MIMEBase
from email.mime.text import MIMEText
from email.utils import COMMASPACE, formatdate
from email import encoders


CONFIG_PATH = Path().joinpath('./input/reportgen.config.json').absolute()


def load_json(filename):
    with open(filename) as json_file:
        return json.load(json_file)


def write_donations_csv(json_data, output_file_path):
    with open(output_file_path, 'w') as file:
        csv_writer = csv.writer(file) 
        header = ['donorName', 'blackbaudId', 'raiseDonorsId', 'donationAmount', 'status']
        csv_writer.writerow(header)
        for donation in json_data:
            csv_writer.writerow([donation['bbDonorName'], donation['bbDonorId'], donation['rdDonorId'], donation['donation']['amount'], donation['rdDonationStatus']['name']])


def write_unmatched_donors_csv(json_data, output_file_path):
    def get_address(addresses):
        try:
            a = addresses[0]
            return f"{a['address1']}, {a['city']}, {a['state']} {a['zip']}"
        except:
            return None
    def sum_donations(donations):
        total = 0
        for d in donations:
            total += float(d['amount'])
        return total
    with open(output_file_path, 'w') as file:
        csv_writer = csv.writer(file) 
        header = ['donorName', 'raiseDonorsId', 'address', 'donationAmount']
        csv_writer.writerow(header)
        for donor in json_data:
            csv_writer.writerow([
                f"{donor['firstName']} {donor['lastName']}",
                donor['id'],
                get_address(donor['addresses']),
                sum_donations(donor['donations'])
            ])


def send_mail(send_from, send_to, subject, message, files=[],
              server='localhost', port=587, username='', password='',
              use_tls=True):
    msg = MIMEMultipart()
    msg['From'] = send_from
    msg['To'] = send_to
    msg['Date'] = formatdate(localtime=True)
    msg['Subject'] = subject
    msg.attach(MIMEText(message))

    for path in files:
        part = MIMEBase('application', "octet-stream")
        with open(path, 'rb') as file:
            part.set_payload(file.read())
        encoders.encode_base64(part)
        part.add_header('Content-Disposition',
                        'attachment; filename="{}"'.format(op.basename(path)))
        msg.attach(part)

    smtp = smtplib.SMTP(server, port)
    if use_tls:
        smtp.starttls()
    smtp.login(username, password)
    smtp.sendmail(send_from, send_to, msg.as_string())
    smtp.quit()


if __name__ == '__main__':
    config = load_json(CONFIG_PATH)

    output_files = []
    run_ids = input('Enter run ID\'s : ')

    try:
        run_ids = run_ids.split(',')
    except:
        print('Invalid list of ID\'s; please use comma separated list of #\'s with no spaces')
        sys.exit(-1)

    for run_id in run_ids:
        run_id = run_id.strip()
        try:
            int(run_id)
        except:
            print('Invalid ID provided, must be an integer type.')
            sys.exit(-1)    

        input_path = f'output/{run_id}/'
        input_summary_path = os.path.join(input_path, 'summary.json')
        input_donations_path = os.path.join(input_path, 'donations.json')
        input_unmatched_path = os.path.join(input_path, 'unmatched_accounts.json')

        if not os.path.exists(input_path):
            print(f'Run with specified ID does not exist at {input_path}')
            sys.exit(-1)
        
        if not os.path.exists(input_summary_path):
            print(f'Run {run_id} does not have summary at {input_summary_path}')
            sys.exit(-1)

        if not os.path.exists(input_donations_path):
            print(f'Run {run_id} does not have donations at {input_donations_path}')
            sys.exit(-1)
        
        if not os.path.exists(input_unmatched_path):
            print(f'Run {run_id} does not have unmatched donors at {input_unmatched_path}')
            sys.exit(-1)
        
        summary_json = load_json(input_summary_path)
        input_donations_json = load_json(input_donations_path)
        input_unmatched_json = load_json(input_unmatched_path)

        label = summary_json['label'].lower()

        output_path = f'output/{run_id}/'
        output_summary_path = os.path.join(output_path, f'{label}_summary_report.csv')
        output_unmatched_path = os.path.join(output_path, f'{label}_unmatched_report.csv')

        write_donations_csv(input_donations_json, output_summary_path)
        write_unmatched_donors_csv(input_unmatched_json, output_unmatched_path)

        output_files.append(output_summary_path)
        output_files.append(output_unmatched_path)

    if config['smtp.enabled']:
        send_mail(
            send_from=config['smtp.from'],
            send_to=','.join(config['smtp.recipients']) if isinstance(config['smtp.recipients'], list) else config['smtp.recipients'],
            subject=config['smtp.subject'],
            message=config['smtp.message'],
            files=output_files,
            server=config['smtp.server'],
            port=config['smtp.port'],
            username=config['smtp.username'],
            password=config['smtp.password']
        )

