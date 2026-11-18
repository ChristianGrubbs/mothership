import tempfile
import unittest
from pathlib import Path
from deploy import STATE_VOLUME, env_args, mount_args

BIND = {'Type': 'bind', 'Source': '/host/db.sqlite', 'Destination': '/data/db.sqlite', 'RW': False}
STATE = {'Type': 'volume', 'Name': STATE_VOLUME, 'Source': '/var/lib/docker/volumes/x/_data',
         'Destination': '/state', 'RW': True}


class MountArgsTest(unittest.TestCase):
    def test_read_only_bind_is_replayed(self):
        self.assertEqual(mount_args([BIND]),
                         ['--mount', 'type=bind,src=/host/db.sqlite,dst=/data/db.sqlite,readonly'])

    def test_writable_bind_keeps_write_access(self):
        self.assertEqual(mount_args([dict(BIND, RW=True)]),
                         ['--mount', 'type=bind,src=/host/db.sqlite,dst=/data/db.sqlite'])

    def test_redeploy_over_our_own_state_volume_is_not_a_failure(self):
        # deploy.py adds this volume itself, so inspecting a container it previously
        # created must not be rejected as an unexpected mount type.
        self.assertEqual(mount_args([BIND, STATE]), mount_args([BIND]))

    def test_unknown_volume_still_fails_closed(self):
        with self.assertRaises(SystemExit):
            mount_args([dict(STATE, Name='someone-elses-volume')])

    def test_unknown_mount_type_still_fails_closed(self):
        with self.assertRaises(SystemExit):
            mount_args([{'Type': 'tmpfs', 'Destination': '/tmp', 'RW': True}])


class EnvArgsTest(unittest.TestCase):
    def test_without_alerts_env_the_running_environment_is_replayed(self):
        self.assertEqual(env_args(['A=1'], Path('/nonexistent/alerts.env')), ['--env', 'A=1'])

    def test_alerts_env_owns_its_keys_over_values_replayed_from_the_last_deploy(self):
        with tempfile.TemporaryDirectory() as directory:
            env_file = Path(directory) / 'alerts.env'
            env_file.write_text('# ntfy\nNTFY_TOPIC=new\n')
            self.assertEqual(env_args(['ALLOWED_ORIGINS=x', 'NTFY_TOPIC=old'], env_file),
                             ['--env', 'ALLOWED_ORIGINS=x', '--env-file', str(env_file)])
            env_file.write_text('NTFY_TOPIC\n')
            self.assertEqual(env_args(['NTFY_TOPIC=old'], env_file), ['--env-file', str(env_file)])


if __name__ == '__main__':
    unittest.main()
