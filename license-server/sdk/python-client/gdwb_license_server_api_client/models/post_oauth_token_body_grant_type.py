from enum import Enum

class PostOauthTokenBodyGrantType(str, Enum):
    CLIENT_CREDENTIALS = "client_credentials"
    LICENSE = "license"
    PASSWORD = "password"

    def __str__(self) -> str:
        return str(self.value)
