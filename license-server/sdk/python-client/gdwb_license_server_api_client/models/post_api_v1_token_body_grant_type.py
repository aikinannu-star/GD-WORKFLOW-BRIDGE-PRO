from enum import Enum

class PostApiV1TokenBodyGrantType(str, Enum):
    CLIENT_CREDENTIALS = "client_credentials"
    LICENSE = "license"
    PASSWORD = "password"

    def __str__(self) -> str:
        return str(self.value)
