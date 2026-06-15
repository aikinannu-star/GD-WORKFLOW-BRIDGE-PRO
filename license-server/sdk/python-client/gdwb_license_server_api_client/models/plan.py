from enum import Enum

class Plan(str, Enum):
    ENTERPRISE = "enterprise"
    FREE = "free"
    PRO = "pro"

    def __str__(self) -> str:
        return str(self.value)
